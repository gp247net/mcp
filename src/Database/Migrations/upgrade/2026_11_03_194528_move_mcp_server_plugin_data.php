<?php

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminHome;
use GP247\Core\Models\AdminMenu;
use GP247\Core\Models\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Move the data of the McpServer plugin (1.x) to gp247/mcp, in place, so tokens, role grants
 * and settings (secrets included) keep working. Idempotent: a site that never had the plugin,
 * or a second run, changes nothing. The plugin's uninstall() is NOT called — it would revoke
 * every MCP token. Personal access tokens are not touched.
 *
 * Irreversible by design (down() is a no-op): the plugin is retired; restore from a backup of
 * admin_config / admin_menu / admin_permission / admin_role_permission to roll back.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-plugin-migration
 * @aidlc-adr ADR-plugin-mcp-server-package-distribution
 */
return new class extends Migration
{
    private const OLD_PREFIX = 'McpServer_';
    private const OLD_CODE = 'McpServer_config';
    private const NEW_GROUP = 'gp247_mcp';
    private const NEW_PREFIX = 'mcp_';
    private const NEW_CODE = 'mcp_config';
    private const PERMISSIONS = [
        'mcp-server.use' => ['slug' => 'mcp.use', 'name' => 'MCP server — use (own tokens)'],
        'mcp-server.configure' => ['slug' => 'mcp.configure', 'name' => 'MCP server — configure'],
    ];

    /**
     * Run the migration.
     */
    public function up(): void
    {
        DB::connection(GP247_DB_CONNECTION)->transaction(function (): void {
            $pluginEnabled = $this->pluginEnabled();
            $this->moveSettings();
            if ($pluginEnabled === false) {
                // WHY: a disabled plugin was the owner's off switch; the endpoint must not come back on.
                $this->table((new AdminConfig)->getTable())
                    ->where('group', self::NEW_GROUP)
                    ->where('key', self::NEW_PREFIX . 'endpoint_enabled')
                    ->update(['value' => '0']);
            }
            $this->movePermissions();
            $this->moveMenu();
            $this->table((new AdminHome)->getTable())->where('extension', 'Plugins/McpServer')->delete();
            // WHY delete the registration rows directly: the plugin stops loading its routes and
            // its uninstall hook can no longer run (it would revoke every MCP token).
            $this->table((new AdminConfig)->getTable())
                ->where('group', 'Plugins')
                ->whereIn('key', ['McpServer', 'McpServer_installed_version'])
                ->delete();
        });
    }

    /**
     * Irreversible (see class comment).
     */
    public function down(): void
    {
    }

    /**
     * State of the plugin registration row: true = enabled, false = disabled, null = never installed.
     */
    private function pluginEnabled(): ?bool
    {
        $value = $this->table((new AdminConfig)->getTable())
            ->where('group', 'Plugins')
            ->where('key', 'McpServer')
            ->value('value');

        return $value === null ? null : ((string) $value === '1');
    }

    /**
     * Rename McpServer_<name> rows to mcp_<name>. When the new key already exists it can only hold
     * a default created by the package screens before this ran, so the plugin's value wins.
     */
    private function moveSettings(): void
    {
        $table = (new AdminConfig)->getTable();
        $rows = $this->table($table)->where('code', self::OLD_CODE)->get(['id', 'key', 'store_id']);
        foreach ($rows as $row) {
            if (!str_starts_with((string) $row->key, self::OLD_PREFIX)) {
                continue;
            }
            $name = substr((string) $row->key, strlen(self::OLD_PREFIX));
            $newKey = self::NEW_PREFIX . $name;
            $taken = $this->table($table)
                ->where('group', self::NEW_GROUP)
                ->where('key', $newKey)
                ->where('store_id', $row->store_id)
                ->first(['id']);
            if ($taken) {
                $old = $this->table($table)->where('id', $row->id)->first(['value', 'security']);
                $this->table($table)->where('id', $taken->id)->update(['value' => $old->value, 'security' => $old->security]);
                $this->table($table)->where('id', $row->id)->delete();
                continue;
            }
            $this->table($table)->where('id', $row->id)->update([
                'group' => self::NEW_GROUP,
                'code' => self::NEW_CODE,
                'key' => $newKey,
                'detail' => 'gp247-mcp::lang.setting.' . $name,
            ]);
        }
    }

    /**
     * Rename the two permissions in place (role and user grants keep their permission id). When the
     * new slug already exists, the grants move to it and the old row goes. The display name changes
     * too, so a re-installed plugin cannot collide on the unique permission name.
     */
    private function movePermissions(): void
    {
        $table = (new AdminPermission)->getTable();
        foreach (self::PERMISSIONS as $oldSlug => $target) {
            $newSlug = $target['slug'];
            $old = $this->table($table)->where('slug', $oldSlug)->first(['id', 'http_uri']);
            if (!$old) {
                continue;
            }
            // WHY replace inside the stored value: keeps the site's own admin prefix exactly.
            $httpUri = str_replace('/mcp-server/', '/mcp/', (string) $old->http_uri);
            $new = $this->table($table)->where('slug', $newSlug)->first(['id']);
            if (!$new) {
                $update = ['slug' => $newSlug, 'http_uri' => $httpUri];
                if (!$this->table($table)->where('name', $target['name'])->exists()) {
                    $update['name'] = $target['name'];
                }
                $this->table($table)->where('id', $old->id)->update($update);
                continue;
            }
            foreach (['admin_role_permission' => 'role_id', 'admin_user_permission' => 'user_id'] as $pivot => $owner) {
                $pivot = GP247_DB_PREFIX . $pivot;
                foreach ($this->table($pivot)->where('permission_id', $old->id)->pluck($owner) as $ownerId) {
                    $this->table($pivot)->insertOrIgnore([$owner => $ownerId, 'permission_id' => $new->id]);
                }
                $this->table($pivot)->where('permission_id', $old->id)->delete();
            }
            $this->table($table)->where('id', $old->id)->delete();
        }
    }

    /**
     * Point the plugin's menu entry to the package screen (keeps its position and parent).
     */
    private function moveMenu(): void
    {
        $table = (new AdminMenu)->getTable();
        if ($this->table($table)->where('key', 'gp247_mcp')->exists()) {
            $this->table($table)->where('key', 'McpServer')->delete();

            return;
        }
        $this->table($table)->where('key', 'McpServer')->update([
            'key' => 'gp247_mcp',
            'title' => 'gp247-mcp::lang.title',
            'uri' => 'route_admin::admin_mcp.tokens',
        ]);
    }

    /**
     * Query builder on the GP247 connection.
     */
    private function table(string $table): Builder
    {
        return DB::connection(GP247_DB_CONNECTION)->table($table);
    }
};
