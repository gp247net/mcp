<?php

/**
 * @aidlc-story US-plugin-mcp-server-package-install
 * @aidlc-story US-plugin-mcp-server-access-token
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminMenu;
use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\PersonalAccessToken;
use GP247\Mcp\Install\Installer;
use GP247\Mcp\McpServiceProvider;
use GP247\Mcp\Support\Settings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerInstallTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        // WHY: start from a site where MCP data was never installed (rolled back after each test).
        AdminConfig::query()->where('group', Settings::GROUP)->delete();
        AdminMenu::query()->where('key', Installer::MENU_KEY)->delete();
        AdminPermission::query()->whereIn('slug', ['mcp.use', 'mcp.configure'])->get()->each->delete();
        Settings::flush();
    }

    public function test_installing_mcp_creates_its_settings_menu_and_permissions_with_the_endpoint_off(): void
    {
        (new Installer)->converge();

        $this->assertSame('0', $this->raw('mcp_endpoint_enabled'));
        $this->assertSame('0', $this->raw('mcp_tool_update_order_status'));
        $this->assertSame('1', $this->raw('mcp_tool_search_orders'));
        $this->assertSame(McpServiceProvider::VERSION, Installer::installedVersion());
        $this->assertSame('ANY::' . GP247_ADMIN_PREFIX . '/mcp/tokens', AdminPermission::query()->where('slug', 'mcp.use')->value('http_uri'));
        $this->assertSame('ANY::' . GP247_ADMIN_PREFIX . '/mcp/settings', AdminPermission::query()->where('slug', 'mcp.configure')->value('http_uri'));
        $this->assertSame('route_admin::admin_mcp.tokens', AdminMenu::query()->where('key', Installer::MENU_KEY)->value('uri'));
        $this->assertFalse(Settings::endpointEnabled());
    }

    public function test_running_install_or_update_again_does_not_duplicate_rows_or_reset_values(): void
    {
        $installer = new Installer;
        $installer->converge();
        $this->mcpSetting('endpoint_enabled', '1');
        $installer->converge();
        $installer->converge();

        $this->assertSame(1, AdminPermission::query()->where('slug', 'mcp.use')->count());
        $this->assertSame(1, AdminMenu::query()->where('key', Installer::MENU_KEY)->count());
        $this->assertSame(1, AdminConfig::query()->where('key', 'mcp_endpoint_enabled')->count());
        $this->assertSame('1', $this->raw('mcp_endpoint_enabled'));
    }

    public function test_the_install_command_reports_the_package_version_in_its_json_envelope(): void
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call('gp247:mcp-install', ['--json' => true], $buffer);
        $output = $buffer->fetch();
        $envelope = $this->envelope($output);

        $this->assertSame(0, $code, $output);
        $this->assertTrue($envelope['ok'] ?? false, $output);
        $this->assertArrayHasKey('error', $envelope);
        $this->assertNull($envelope['error']);
        $this->assertSame(McpServiceProvider::VERSION, $envelope['data']['version'] ?? null);
        $this->assertTrue(AdminPermission::query()->where('slug', 'mcp.use')->exists());
    }

    public function test_uninstalling_mcp_removes_its_data_and_revokes_every_mcp_token_but_no_other_token(): void
    {
        (new Installer)->converge();
        $user = $this->administrator();
        $this->tokenFor($user);
        $other = $user->createToken('not-mcp', ['admin'])->accessToken;

        $code = Artisan::call('gp247:mcp-uninstall', ['--force' => true, '--json' => true]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertFalse(AdminConfig::query()->where('group', Settings::GROUP)->exists());
        $this->assertFalse(AdminPermission::query()->whereIn('slug', ['mcp.use', 'mcp.configure'])->exists());
        $this->assertFalse(AdminMenu::query()->where('key', Installer::MENU_KEY)->exists());
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $user->id)->where('abilities', 'like', '%mcp:%')->count());
        $this->assertTrue(PersonalAccessToken::query()->whereKey($other->id)->exists());
    }

    public function test_uninstall_refuses_to_run_unattended_without_force(): void
    {
        (new Installer)->converge();

        $buffer = new BufferedOutput();
        $code = Artisan::call('gp247:mcp-uninstall', ['--json' => true], $buffer);
        $envelope = $this->envelope($buffer->fetch());

        $this->assertNotSame(0, $code);
        $this->assertSame('confirmation_required', $envelope['error']['code'] ?? null);
        $this->assertTrue(AdminPermission::query()->where('slug', 'mcp.use')->exists());
    }

    public function test_before_installation_the_site_runs_with_mcp_disabled(): void
    {
        $this->assertFalse(Settings::endpointEnabled());
        $this->assertNull(Installer::installedVersion());
        $this->postJson(self::MCP_URL, [])->assertNotFound();
    }

    /**
     * JSON envelope of a gp247:* command: the last JSON line (nested commands may print progress lines
     * into the same in-process buffer).
     *
     * @return array<string, mixed>
     */
    private function envelope(string $output): array
    {
        $lines = array_reverse(array_filter(array_map('trim', preg_split('/\R/', $output))));
        foreach ($lines as $line) {
            if (str_starts_with($line, '{')) {
                return (array) json_decode($line, true);
            }
        }

        return [];
    }

    /**
     * Stored value of one MCP setting (raw column, no model accessor).
     */
    private function raw(string $key): ?string
    {
        $value = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('group', Settings::GROUP)->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }
}
