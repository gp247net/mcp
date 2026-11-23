<?php

/**
 * @aidlc-story US-plugin-mcp-server-audit-log
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Core\Models\AdminLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerAuditLogTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    private int $logBaseline = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
        $this->logBaseline = (int) AdminLog::max('id');
    }

    private function mcpLogs()
    {
        return AdminLog::query()->where('id', '>', $this->logBaseline)->where('method', 'MCP')->orderBy('id')->get();
    }

    public function test_a_successful_tool_call_writes_one_log_row_for_the_token_owner(): void
    {
        $user = $this->administrator();
        $token = $this->tokenFor($user);

        $this->callTool($token, 'store_info')->assertOk();

        $rows = $this->mcpLogs();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $user->id, (string) $rows[0]->user_id);
        $this->assertSame('mcp/store_info', $rows[0]->path);
        $input = json_decode((string) $rows[0]->input, true);
        $this->assertSame('ok', $input['outcome']);
        $this->assertSame('zz-mcp-test', $input['token']['name']);
        $this->assertStringNotContainsString(explode('|', $token, 2)[1], (string) $rows[0]->input);
    }

    public function test_a_denied_call_is_logged_as_denied(): void
    {
        $viewer = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order']);

        $this->callTool($this->tokenFor($viewer), 'search_customers');

        $rows = $this->mcpLogs();
        $this->assertCount(1, $rows);
        $this->assertSame('denied', json_decode((string) $rows[0]->input, true)['outcome']);
    }

    public function test_listing_tools_writes_no_log_row(): void
    {
        $this->mcp($this->tokenFor($this->administrator()), 'tools/list')->assertOk();

        $this->assertCount(0, $this->mcpLogs());
    }

    public function test_logged_arguments_never_contain_contact_details_or_confirm_codes(): void
    {
        $this->mcpSetting('tool_update_order_status', '1');
        $user = $this->administrator();
        $token = $this->tokenFor($user, ['mcp:read', 'mcp:write']);
        $order = $this->makeMcpOrder();

        $this->callTool($token, 'search_customers', ['keyword' => 'zzsecret@example.test']);
        $code = $this->structured($this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 2]))['confirm_code'];
        $this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 2, 'confirm_code' => $code]);

        $all = $this->mcpLogs()->pluck('input')->implode("\n");
        $this->assertStringNotContainsString('zzsecret@example.test', $all);
        $this->assertStringNotContainsString($code, $all);
        $this->assertCount(3, $this->mcpLogs());
    }
}
