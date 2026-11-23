<?php

/**
 * @aidlc-story US-plugin-mcp-server-connect-guide
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Mcp\Livewire\McpSettings;
use GP247\Mcp\Livewire\TokenManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerConnectGuideTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
    }

    public function test_the_token_screen_shows_the_endpoint_url_and_client_snippets_without_a_token(): void
    {
        $this->enableEndpoint();
        $this->actingAs($this->mcpUser(), 'admin');
        $url = url('api/core/mcp');

        Livewire::test(TokenManager::class)
            ->assertSeeHtml('data-testid="plugin-mcp-server-endpoint-url"')
            ->assertSee($url)
            ->assertSeeHtml('data-testid="plugin-mcp-server-snippet-claude-code"')
            ->assertSeeHtml('data-testid="plugin-mcp-server-snippet-cursor"')
            ->assertSeeHtml('data-testid="plugin-mcp-server-snippet-vscode"')
            ->assertSee('GP247_MCP_TOKEN');
    }

    public function test_the_token_screen_warns_while_the_endpoint_is_disabled(): void
    {
        $this->actingAs($this->mcpUser(), 'admin');

        Livewire::test(TokenManager::class)->assertSeeHtml('data-testid="plugin-mcp-server-endpoint-disabled"');
    }

    public function test_the_probe_reports_whether_the_authorization_header_reaches_php(): void
    {
        $this->enableEndpoint();

        $this->getJson('api/core/mcp-probe', ['Authorization' => 'Bearer gp247-probe'])
            ->assertOk()->assertJson(['ok' => true, 'authorization_header' => true]);
        $this->getJson('api/core/mcp-probe')
            ->assertOk()->assertJson(['ok' => true, 'authorization_header' => false]);
    }

    public function test_the_probe_is_hidden_while_the_endpoint_is_disabled(): void
    {
        $this->getJson('api/core/mcp-probe')->assertNotFound();
    }

    public function test_the_check_button_warns_when_the_web_server_strips_the_authorization_header(): void
    {
        $this->enableEndpoint();
        $this->actingAs($this->mcpUser(), 'admin');
        Http::fake(['*' => Http::response(['ok' => true, 'authorization_header' => false, 'https' => true])]);

        Livewire::test(TokenManager::class)
            ->call('checkConnection')
            ->assertSeeHtml('data-testid="plugin-mcp-server-check-header-missing"');
    }

    public function test_the_check_calls_the_configured_site_url_whatever_the_request_host(): void
    {
        $this->enableEndpoint();
        config(['app.url' => 'https://shop.example.test']);
        $this->actingAs($this->mcpUser(), 'admin');
        Http::fake(['*' => Http::response(['ok' => true, 'authorization_header' => true, 'https' => true])]);

        Livewire::withHeaders(['Host' => 'internal.attacker.example'])->test(TokenManager::class)->call('checkConnection');

        Http::assertSent(fn ($request) => $request->url() === 'https://shop.example.test/api/core/mcp-probe');
    }

    public function test_the_check_button_confirms_a_working_endpoint(): void
    {
        $this->enableEndpoint();
        $this->actingAs($this->mcpUser(), 'admin');
        Http::fake(['*' => Http::response(['ok' => true, 'authorization_header' => true, 'https' => true])]);

        Livewire::test(TokenManager::class)
            ->call('checkConnection')
            ->assertSeeHtml('data-testid="plugin-mcp-server-check-ok"');
    }

    public function test_the_settings_screen_lets_the_owner_enable_the_endpoint_and_tools(): void
    {
        $this->actingAs($this->administrator(), 'admin');

        Livewire::test(McpSettings::class)
            ->assertSeeHtml('data-testid="config-form-submit"')
            ->assertSee(url('api/core/mcp'))
            ->set('values.mcp_endpoint_enabled', true)
            ->call('save');

        \GP247\Mcp\Support\Settings::flush();
        $this->assertTrue(\GP247\Mcp\Support\Settings::endpointEnabled());
    }
}
