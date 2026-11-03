<?php

namespace GP247\Mcp\Install;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminMenu;
use GP247\Core\Models\AdminPermission;
use GP247\Mcp\McpServiceProvider;
use GP247\Mcp\Services\TokenService;
use GP247\Mcp\Support\AdminPrefix;
use GP247\Mcp\Support\Routes;
use GP247\Mcp\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Data lifecycle of gp247/mcp: settings rows, admin menu entry and the two permissions.
 * converge() is idempotent (install and update run the same code); uninstall() removes the
 * data and revokes every MCP token. The package owns no table.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-package-install
 * @aidlc-adr ADR-plugin-mcp-server-package-distribution
 */
final class Installer
{
    public const MENU_KEY = 'gp247_mcp';
    private const MENU_PARENT_KEY = 'ADMIN_SYSTEM';
    private const VERSION_SETTING = 'installed_version';

    /**
     * Permissions of MCP: "use" = token screen and MCP itself (L1), "configure" = settings screen.
     *
     * @return array<int, array{slug: string, name: string, http_uri: string}>
     */
    public static function permissionDefinitions(): array
    {
        return [
            ['slug' => 'mcp.use', 'name' => 'MCP server — use (own tokens)', 'http_uri' => 'ANY::' . AdminPrefix::path(Routes::ADMIN_URI . '/tokens')],
            ['slug' => 'mcp.configure', 'name' => 'MCP server — configure', 'http_uri' => 'ANY::' . AdminPrefix::path(Routes::ADMIN_URI . '/settings')],
        ];
    }

    /**
     * Whether the GP247 tables MCP writes to exist (the platform is installed in this database).
     */
    public static function platformTablesExist(): bool
    {
        $connection = defined('GP247_DB_CONNECTION') ? GP247_DB_CONNECTION : null;
        $schema = Schema::connection($connection);

        return $schema->hasTable((new AdminConfig)->getTable())
            && $schema->hasTable((new AdminMenu)->getTable())
            && $schema->hasTable((new AdminPermission)->getTable());
    }

    /**
     * Bring settings rows, menu and permissions to the state of this package version.
     *
     * @throws RuntimeException When the platform tables are missing.
     */
    public function converge(): void
    {
        $this->assertPlatformTables();

        Settings::ensureRows();
        $this->installMenu();
        $this->installPermissions();
        AdminConfig::query()->updateOrCreate(
            ['group' => Settings::GROUP, 'key' => Settings::key(self::VERSION_SETTING), 'store_id' => self::globalStoreId()],
            ['code' => Settings::CODE, 'sort' => 0, 'value' => McpServiceProvider::VERSION, 'security' => 0, 'detail' => '']
        );
        Settings::flush();
    }

    /**
     * Remove settings, menu and permissions, and revoke every MCP token (removing MCP must cut
     * all agent access). Other API tokens are untouched.
     *
     * @return int Number of MCP tokens revoked.
     * @throws RuntimeException When the platform tables are missing.
     */
    public function uninstall(): int
    {
        $this->assertPlatformTables();

        // WHY tokens first, in one transaction: a failure must never leave agent access alive on a
        // half-removed install.
        $revoked = DB::connection(defined('GP247_DB_CONNECTION') ? GP247_DB_CONNECTION : null)->transaction(static function (): int {
            $revoked = app(TokenService::class)->revokeAll();
            AdminConfig::query()->where('group', Settings::GROUP)->where('code', Settings::CODE)->delete();
            AdminMenu::query()->where('key', self::MENU_KEY)->delete();
            // WHY one by one: the model's deleting hook detaches the role links.
            AdminPermission::query()
                ->whereIn('slug', array_column(self::permissionDefinitions(), 'slug'))
                ->get()
                ->each(static fn (AdminPermission $permission) => $permission->delete());

            return $revoked;
        });
        Settings::flush();

        return $revoked;
    }

    /**
     * Version of the data last converged on this site, or null when MCP was never installed.
     */
    public static function installedVersion(): ?string
    {
        $value = AdminConfig::query()
            ->where('group', Settings::GROUP)
            ->where('key', Settings::key(self::VERSION_SETTING))
            ->where('store_id', self::globalStoreId())
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * Fail before writing anything when the platform is not installed in this database.
     *
     * @throws RuntimeException
     */
    private function assertPlatformTables(): void
    {
        if (!self::platformTablesExist()) {
            throw new RuntimeException('GP247 is not installed in this database (admin tables are missing). Run php artisan gp247:install first.');
        }
    }

    /**
     * Admin menu entry under System (kept in place when it already exists).
     */
    private function installMenu(): void
    {
        if (AdminMenu::query()->where('key', self::MENU_KEY)->exists()) {
            return;
        }
        $parent = AdminMenu::query()->where('key', self::MENU_PARENT_KEY)->first();
        if (!$parent) {
            return;
        }

        AdminMenu::query()->insert([
            'sort' => 91,
            'parent_id' => $parent->id,
            'title' => 'gp247-mcp::lang.title',
            'icon' => 'fas fa-robot',
            'uri' => 'route_admin::admin_mcp.tokens',
            'key' => self::MENU_KEY,
        ]);
    }

    /**
     * Create the permissions and repair an http_uri that drifted (e.g. admin prefix changed).
     */
    private function installPermissions(): void
    {
        foreach (self::permissionDefinitions() as $definition) {
            $permission = AdminPermission::query()->firstOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name'], 'http_uri' => $definition['http_uri']]
            );
            if ($permission->http_uri !== $definition['http_uri']) {
                $permission->update(['http_uri' => $definition['http_uri']]);
            }
        }
    }

    /**
     * Store id of site-wide settings.
     */
    private static function globalStoreId(): string
    {
        return (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0);
    }
}
