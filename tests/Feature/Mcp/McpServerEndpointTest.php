<?php

/**
 * @aidlc-story US-plugin-mcp-server-endpoint
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerEndpointTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
    }

    public function test_a_freshly_installed_site_answers_404_until_the_owner_enables_the_endpoint(): void
    {
        $token = $this->tokenFor($this->administrator());

        $this->mcp($token, 'tools/list')->assertNotFound();

        $this->enableEndpoint();
        $this->mcp($token, 'tools/list')->assertOk();
    }

    public function test_the_endpoint_answers_plain_json_without_session_cookie(): void
    {
        $this->enableEndpoint();
        $response = $this->mcp($this->tokenFor($this->administrator()), 'tools/list');

        $response->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame([], $response->headers->all('set-cookie'));
        $this->assertNull($response->headers->get('Mcp-Session-Id'));
    }

    public function test_only_post_is_accepted(): void
    {
        $this->enableEndpoint();

        $this->get(self::MCP_URL)->assertStatus(405);
    }

    public function test_a_request_without_a_token_is_refused_with_a_bearer_challenge(): void
    {
        $this->enableEndpoint();
        $response = $this->mcp(null, 'tools/list');

        $response->assertStatus(401);
        $this->assertStringContainsStringIgnoringCase('Bearer', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_an_unknown_token_and_an_expired_token_get_the_same_refusal(): void
    {
        $this->enableEndpoint();
        $expired = $this->tokenFor($this->administrator(), ['mcp:read'], now()->subMinute());

        $unknown = $this->mcp('1|not-a-real-token', 'tools/list');
        $late = $this->mcp($expired, 'tools/list');

        $unknown->assertStatus(401);
        $late->assertStatus(401);
        $this->assertSame($unknown->getContent(), $late->getContent());
    }

    public function test_a_token_without_mcp_ability_cannot_use_the_endpoint(): void
    {
        $this->enableEndpoint();

        $this->mcp($this->tokenFor($this->administrator(), ['admin']), 'tools/list')->assertStatus(403);
    }

    public function test_a_locked_admin_account_cannot_use_its_tokens(): void
    {
        $this->enableEndpoint();
        $user = $this->administrator();
        $token = $this->tokenFor($user);
        $user->forceFill(['status' => 0])->save();

        $this->mcp($token, 'tools/list')->assertStatus(401);
    }

    public function test_a_bad_token_and_a_locked_account_get_the_same_unauthenticated_body(): void
    {
        $this->enableEndpoint();
        $user = $this->administrator();
        $token = $this->tokenFor($user);
        $user->forceFill(['status' => 0])->save();

        $unknown = $this->mcp('1|not-a-real-token', 'tools/list');
        $locked = $this->mcp($token, 'tools/list');

        $unknown->assertStatus(401);
        $locked->assertStatus(401);
        $this->assertSame($unknown->getContent(), $locked->getContent());
        $this->assertSame(1, $locked->json('error'));
        $this->assertSame('unauthenticated', $locked->json('code'));
        $this->assertStringContainsStringIgnoringCase('Bearer', (string) $locked->headers->get('WWW-Authenticate'));
    }

    public function test_refusals_at_the_edge_use_the_gp247_error_envelope(): void
    {
        $this->enableEndpoint();
        $token = $this->tokenFor($this->administrator());

        $origin = $this->mcp($token, 'tools/list', [], ['Origin' => 'https://evil.example']);
        $scope = $this->mcp($this->tokenFor($this->administrator(), ['admin']), 'tools/list');
        $this->mcpSetting('max_request_kb', '1');
        $large = $this->callTool($token, 'search_products', ['keyword' => str_repeat('x', 4000)]);

        foreach ([[$origin, 403, 'forbidden_origin'], [$scope, 403, 'insufficient_scope'], [$large, 413, 'payload_too_large']] as [$response, $status, $code]) {
            $response->assertStatus($status);
            $this->assertSame(1, $response->json('error'), $code);
            $this->assertSame($code, $response->json('code'));
            $this->assertIsString($response->json('msg'));
        }
    }

    public function test_the_pre_auth_ip_budget_answers_with_the_envelope_and_retry_after(): void
    {
        $this->enableEndpoint();
        $this->mcpSetting('throttle_per_minute', '1');
        $last = null;
        for ($i = 0; $i < 7; $i++) {
            $last = $this->mcp('1|bad-token-' . $i, 'tools/list');
        }

        $last->assertStatus(429);
        $this->assertSame('too_many_attempts', $last->json('code'));
        $this->assertNotNull($last->headers->get('Retry-After'));
    }

    public function test_turning_the_master_api_switch_off_hides_the_endpoint_and_the_probe(): void
    {
        $this->enableEndpoint();
        $token = $this->tokenFor($this->administrator());
        $this->mcp($token, 'tools/list')->assertOk();

        $this->mcpApiSwitch('api_enabled', false);

        $this->mcp($token, 'tools/list')->assertNotFound();
        $this->get('/api/core/mcp-probe')->assertNotFound();
    }

    public function test_the_api_group_switches_do_not_close_mcp(): void
    {
        $this->enableEndpoint();
        $token = $this->tokenFor($this->administrator());
        foreach (['api_v1_core', 'api_v1_front', 'api_v2_core', 'api_v2_front'] as $key) {
            $this->mcpApiSwitch($key, false);
        }

        $this->mcp($token, 'tools/list')->assertOk();
    }

    public function test_a_foreign_browser_origin_is_refused_and_the_site_origin_is_accepted(): void
    {
        $this->enableEndpoint();
        $token = $this->tokenFor($this->administrator());

        $this->mcp($token, 'tools/list', [], ['Origin' => 'https://evil.example'])->assertStatus(403);
        $this->mcp($token, 'tools/list', [], ['Origin' => rtrim((string) config('app.url'), '/')])->assertOk();
    }

    public function test_an_origin_equal_to_a_spoofed_host_header_is_still_refused(): void
    {
        $this->enableEndpoint();
        $token = $this->tokenFor($this->administrator());

        $this->mcp($token, 'tools/list', [], ['Origin' => 'http://rebind.attacker.example', 'Host' => 'rebind.attacker.example'])
            ->assertStatus(403);
    }

    public function test_a_localhost_origin_is_refused_when_the_site_is_not_on_localhost(): void
    {
        // WHY: laravel/mcp's own origin check lets every localhost origin through; the GP247 gate
        // decides alone and must not, or a page on the agent's machine could drive the endpoint.
        config(['app.url' => 'https://shop.example.com']);
        $this->enableEndpoint();
        $token = $this->tokenFor($this->administrator());

        foreach (['http://localhost:3000', 'http://127.0.0.1:8080'] as $origin) {
            $response = $this->mcp($token, 'tools/list', [], ['Origin' => $origin]);
            $response->assertStatus(403);
            $this->assertSame('forbidden_origin', $response->json('code'), $origin);
        }
    }

    public function test_a_wildcard_sanctum_token_is_not_an_mcp_token(): void
    {
        $this->enableEndpoint();

        $this->mcp($this->tokenFor($this->administrator(), ['*']), 'tools/list')->assertStatus(403);
    }

    public function test_the_get_route_added_by_the_library_also_hides_a_disabled_endpoint(): void
    {
        $this->get(self::MCP_URL)->assertNotFound();
    }

    public function test_requests_with_bad_tokens_are_throttled_per_ip_before_authentication(): void
    {
        $this->enableEndpoint();
        $this->mcpSetting('throttle_per_minute', '1');
        $last = null;
        for ($i = 0; $i < 7; $i++) {
            $last = $this->mcp('1|bad-token-' . $i, 'tools/list');
        }

        $last->assertStatus(429);
    }

    public function test_an_extra_allowed_origin_can_be_configured(): void
    {
        $this->enableEndpoint();
        $this->mcpSetting('allowed_origins', 'https://agent.example.com, https://other.example');
        $token = $this->tokenFor($this->administrator());

        $this->mcp($token, 'tools/list', [], ['Origin' => 'https://agent.example.com'])->assertOk();
    }

    public function test_an_oversized_request_body_is_refused(): void
    {
        $this->enableEndpoint();
        $this->mcpSetting('max_request_kb', '1');
        $token = $this->tokenFor($this->administrator());

        $this->callTool($token, 'search_products', ['keyword' => str_repeat('x', 4000)])->assertStatus(413);
    }

    public function test_too_many_requests_are_throttled(): void
    {
        $this->enableEndpoint();
        $this->mcpSetting('throttle_per_minute', '2');
        $token = $this->tokenFor($this->administrator());

        $this->mcp($token, 'tools/list')->assertOk();
        $this->mcp($token, 'tools/list')->assertOk();
        $this->mcp($token, 'tools/list')->assertStatus(429);
    }

    public function test_a_new_protocol_request_without_mirrored_headers_is_rejected(): void
    {
        $this->enableEndpoint();
        $token = $this->tokenFor($this->administrator());

        $response = $this->mcp($token, 'tools/list', [], ['Mcp-Method' => 'tools/call']);

        $response->assertStatus(400);
        $this->assertSame(-32020, $response->json('error.code'));
    }

    public function test_a_legacy_initialize_client_is_served_without_a_session(): void
    {
        $this->enableEndpoint();
        $headers = ['Authorization' => 'Bearer ' . $this->tokenFor($this->administrator()), 'Accept' => 'application/json, text/event-stream'];

        $init = $this->postJson(self::MCP_URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'legacy', 'version' => '1'],
        ]], $headers);
        $list = $this->postJson(self::MCP_URL, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers + ['MCP-Protocol-Version' => '2025-11-25']);

        $init->assertOk();
        $this->assertSame('2025-11-25', $init->json('result.protocolVersion'));
        $list->assertOk();
        $this->assertContains('store_info', collect($list->json('result.tools'))->pluck('name')->all());
    }
}
