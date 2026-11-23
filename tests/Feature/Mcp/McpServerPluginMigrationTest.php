<?php

/**
 * @aidlc-story US-plugin-mcp-server-plugin-migration
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminMenu;
use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Mcp\Install\Installer;
use GP247\Mcp\Livewire\TokenManager;
use GP247\Mcp\McpServiceProvider;
use GP247\Mcp\Support\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('serial')]
class McpServerPluginMigrationTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    private const MIGRATION = 'vendor/gp247/mcp/src/Database/Migrations/upgrade/2026_11_03_194528_move_mcp_server_plugin_data.php';

    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        // WHY: rebuild the state of a site still on the McpServer plugin 1.x, inside the rollback.
        AdminConfig::query()->where('group', Settings::GROUP)->delete();
        AdminMenu::query()->where('key', Installer::MENU_KEY)->delete();
        AdminPermission::query()->whereIn('slug', ['mcp.use', 'mcp.configure', 'mcp-server.use', 'mcp-server.configure'])->get()->each->delete();
        $this->config()->where('group', 'Plugins')->where(fn ($q) => $q->where('key', 'like', 'McpServer%')->orWhere('code', 'McpServer_config'))->delete();
        AdminMenu::query()->where('key', 'McpServer')->delete();
        Settings::flush();
    }

    public function test_a_site_on_the_plugin_keeps_its_settings_role_grants_and_tokens_after_the_update(): void
    {
        $this->seedPluginState();
        $user = $this->makeAdmin(['GET::' . GP247_ADMIN_PREFIX . '/order']);
        $user->roles()->attach($this->roleId);
        $token = $this->tokenFor($user);

        $this->runMigration();
        (new Installer)->converge();
        Settings::flush();

        $this->assertSame('1', $this->raw('mcp_endpoint_enabled'));
        $this->assertSame('120', $this->raw('mcp_throttle_per_minute'));
        $this->assertSame('https://agent.example.test', $this->raw('mcp_allowed_origins'), 'stored values move verbatim');
        $this->assertSame('gp247-mcp::lang.setting.endpoint_enabled', $this->config()->where('key', 'mcp_endpoint_enabled')->value('detail'));
        $this->assertFalse($this->config()->where('code', 'McpServer_config')->exists());
        $this->assertFalse($this->config()->where('group', 'Plugins')->whereIn('key', ['McpServer', 'McpServer_installed_version'])->exists());

        $use = AdminPermission::query()->where('slug', 'mcp.use')->first();
        $this->assertSame($this->oldUseId, $use->id, 'renamed in place');
        $this->assertSame('MCP server — use (own tokens)', $use->name, 'new name frees the old one for a stray plugin re-install');
        $this->assertSame('ANY::' . GP247_ADMIN_PREFIX . '/mcp/tokens', $use->http_uri);
        $this->assertTrue(DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_role_permission')
            ->where('role_id', $this->roleId)->where('permission_id', $use->id)->exists());
        $this->assertSame('route_admin::admin_mcp.tokens', AdminMenu::query()->where('key', Installer::MENU_KEY)->value('uri'));
        $this->assertFalse(McpServiceProvider::legacyPluginActive());

        $this->assertContains('search_orders', $this->toolNames($token), 'an agent token issued by the plugin still works');
    }

    public function test_running_the_migration_twice_changes_nothing_the_second_time(): void
    {
        $this->seedPluginState();
        $this->runMigration();
        $before = $this->snapshot();

        $this->runMigration();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_site_that_never_had_the_plugin_is_left_untouched(): void
    {
        $before = $this->snapshot();

        $this->runMigration();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_plugin_value_replaces_a_default_created_by_the_mcp_screens_before_the_update(): void
    {
        $this->seedPluginState();
        // WHY: opening the package settings screen before the update auto-creates default rows.
        $this->config()->insert(['group' => Settings::GROUP, 'code' => Settings::CODE, 'key' => 'mcp_throttle_per_minute', 'store_id' => (string) GP247_STORE_ID_GLOBAL, 'value' => '60', 'sort' => 0, 'detail' => '']);

        $this->runMigration();

        $this->assertSame(1, $this->config()->where('key', 'mcp_throttle_per_minute')->count());
        $this->assertSame('120', $this->raw('mcp_throttle_per_minute'));
        $this->assertFalse($this->config()->where('key', 'McpServer_throttle_per_minute')->exists());
    }

    public function test_a_plugin_the_owner_had_disabled_does_not_come_back_on_after_the_update(): void
    {
        $this->seedPluginState();
        $this->config()->where('group', 'Plugins')->where('key', 'McpServer')->update(['value' => '0']);

        $this->runMigration();

        $this->assertSame('0', $this->raw('mcp_endpoint_enabled'));
        Settings::flush();
        $this->assertFalse(Settings::endpointEnabled());
    }

    public function test_grants_move_to_an_existing_new_permission_instead_of_duplicating_it(): void
    {
        $this->seedPluginState();
        $new = AdminPermission::query()->create(['name' => 'MCP — use (created earlier)', 'slug' => 'mcp.use', 'http_uri' => 'ANY::' . GP247_ADMIN_PREFIX . '/mcp/tokens']);
        $direct = $this->makeAdmin();
        $direct->permissions()->attach($this->oldUseId);

        $this->runMigration();

        $this->assertSame(1, AdminPermission::query()->where('slug', 'mcp.use')->count());
        $this->assertFalse(AdminPermission::query()->where('slug', 'mcp-server.use')->exists());
        $this->assertTrue(DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_role_permission')
            ->where('role_id', $this->roleId)->where('permission_id', $new->id)->exists());
        $this->assertTrue(DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_user_permission')
            ->where('user_id', $direct->id)->where('permission_id', $new->id)->exists(), 'direct user grants move too');
    }

    public function test_the_cleanup_hint_offers_the_purge_command_once_the_old_plugin_is_no_longer_registered(): void
    {
        (new Installer)->converge();
        $this->actingAs($this->administrator(), 'admin');

        $this->withLegacyPluginFolder(function (): void {
            Livewire::test(TokenManager::class)
                ->assertSeeHtml('data-testid="plugin-mcp-server-legacy-plugin"')
                ->assertSee('--purge')
                ->assertDontSeeHtml('data-testid="plugin-mcp-server-legacy-plugin-installed"');
        });
    }

    public function test_a_reinstalled_old_plugin_is_never_offered_the_uninstall_command(): void
    {
        (new Installer)->converge();
        // WHY: the plugin's own install() re-creates its registration row; uninstalling it then would revoke every MCP token.
        $this->config()->insert(['group' => 'Plugins', 'code' => 'Other', 'key' => 'McpServer', 'store_id' => (string) GP247_STORE_ID_GLOBAL, 'value' => '1', 'sort' => 0, 'detail' => '']);
        $this->actingAs($this->administrator(), 'admin');

        $this->withLegacyPluginFolder(function (): void {
            Livewire::test(TokenManager::class)
                ->assertSeeHtml('data-testid="plugin-mcp-server-legacy-plugin-installed"')
                ->assertSee('gp247:ext-disable')
                ->assertDontSee('--purge');
        });
    }

    /**
     * Run a check while an empty app/GP247/Plugins/McpServer folder exists (removed afterwards).
     */
    private function withLegacyPluginFolder(callable $check): void
    {
        $dir = app_path('GP247/Plugins/' . McpServiceProvider::LEGACY_PLUGIN_KEY);
        if (is_dir($dir)) {
            $this->markTestSkipped('A real McpServer plugin folder exists on this site.');
        }
        mkdir($dir);
        try {
            $check();
        } finally {
            @rmdir($dir);
        }
    }

    private int $oldUseId = 0;

    /**
     * Data of McpServer plugin 1.1.0: registration rows, settings, two permissions granted to a role, menu.
     */
    private function seedPluginState(): void
    {
        $global = (string) GP247_STORE_ID_GLOBAL;
        $this->config()->insert([
            ['group' => 'Plugins', 'code' => 'Other', 'key' => 'McpServer', 'store_id' => $global, 'value' => '1', 'sort' => 0, 'detail' => 'Plugins/McpServer::lang.title'],
            ['group' => 'Plugins', 'code' => 'extension_version', 'key' => 'McpServer_installed_version', 'store_id' => $global, 'value' => '1.1.0', 'sort' => 0, 'detail' => ''],
            ['group' => 'Plugins', 'code' => 'McpServer_config', 'key' => 'McpServer_endpoint_enabled', 'store_id' => $global, 'value' => '1', 'sort' => 1, 'detail' => 'Plugins/McpServer::lang.setting.endpoint_enabled'],
            ['group' => 'Plugins', 'code' => 'McpServer_config', 'key' => 'McpServer_throttle_per_minute', 'store_id' => $global, 'value' => '120', 'sort' => 2, 'detail' => 'Plugins/McpServer::lang.setting.throttle_per_minute'],
            ['group' => 'Plugins', 'code' => 'McpServer_config', 'key' => 'McpServer_allowed_origins', 'store_id' => $global, 'value' => 'https://agent.example.test', 'sort' => 3, 'detail' => 'Plugins/McpServer::lang.setting.allowed_origins'],
        ]);
        $use = AdminPermission::query()->create(['name' => 'MCP — use (own tokens)', 'slug' => 'mcp-server.use', 'http_uri' => 'ANY::' . GP247_ADMIN_PREFIX . '/mcp-server/tokens']);
        AdminPermission::query()->create(['name' => 'MCP — configure', 'slug' => 'mcp-server.configure', 'http_uri' => 'ANY::' . GP247_ADMIN_PREFIX . '/mcp-server/settings']);
        $this->oldUseId = (int) $use->id;
        $role = AdminRole::query()->create(['name' => 'ZZ MCP role', 'slug' => 'zz-mcp-' . Str::lower(Str::random(6))]);
        $role->permissions()->attach($use->id);
        $this->roleId = (int) $role->id;
        $parent = AdminMenu::query()->where('key', 'ADMIN_SYSTEM')->value('id') ?? 0;
        AdminMenu::query()->insert(['sort' => 91, 'parent_id' => $parent, 'title' => 'Plugins/McpServer::lang.title', 'icon' => 'fas fa-robot', 'uri' => 'route_admin::admin_mcp_server.tokens', 'key' => 'McpServer']);
    }

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    /**
     * Rows the migration may touch, for before/after comparison.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $db = DB::connection(GP247_DB_CONNECTION);

        return [
            'config' => $this->config()->where(fn ($q) => $q->whereIn('group', ['Plugins', Settings::GROUP]))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'permission' => $db->table(GP247_DB_PREFIX . 'admin_permission')->where('slug', 'like', 'mcp%')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'menu' => $db->table(GP247_DB_PREFIX . 'admin_menu')->whereIn('key', ['McpServer', Installer::MENU_KEY])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function config(): Builder
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
    }

    private function raw(string $key): ?string
    {
        $value = $this->config()->where('group', Settings::GROUP)->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }
}
