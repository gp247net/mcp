<?php

/**
 * @aidlc-story US-plugin-mcp-server-order-note-write
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Core\Models\AdminLog;
use GP247\Shop\Models\ShopOrderHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerOrderNoteWriteTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
        $this->mcpSetting('tool_add_order_note', '1');
    }

    private function writer(): array
    {
        $user = $this->mcpUser(['ANY::' . GP247_ADMIN_PREFIX . '/order/*']);

        return [$user, $this->tokenFor($user, ['mcp:read', 'mcp:write'])];
    }

    public function test_the_tool_is_off_after_install_until_the_owner_turns_it_on(): void
    {
        [, $token] = $this->writer();
        $this->mcpSetting('tool_add_order_note', '0');

        $this->assertNotContains('add_order_note', $this->toolNames($token));
    }

    public function test_a_note_is_added_in_one_call_labelled_mcp_and_escaped(): void
    {
        [$user, $token] = $this->writer();
        $order = $this->makeMcpOrder();

        $data = $this->structured($this->callTool($token, 'add_order_note', ['order_id' => $order->id, 'note' => 'Deliver after <b>5pm</b>']));

        $this->assertTrue($data['applied']);
        $history = ShopOrderHistory::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertSame((string) $user->id, (string) $history->admin_id);
        $this->assertStringStartsWith('[MCP] ', (string) $history->content);
        $this->assertStringContainsString('&lt;b&gt;5pm', (string) $history->content);
        $this->assertSame((int) $order->status, (int) $order->fresh()->status);
    }

    public function test_an_empty_or_control_only_note_is_refused(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder();

        $this->assertTrue($this->failed($this->callTool($token, 'add_order_note', ['order_id' => $order->id, 'note' => "\x01\x02 "])));
        $this->assertSame(0, ShopOrderHistory::query()->where('order_id', $order->id)->count());
    }

    public function test_the_audit_log_keeps_the_note_length_not_its_text(): void
    {
        [$user, $token] = $this->writer();
        $order = $this->makeMcpOrder();

        $this->callTool($token, 'add_order_note', ['order_id' => $order->id, 'note' => 'Customer phone is 0901234567, call first'])->assertOk();

        $log = AdminLog::query()->where('user_id', (string) $user->id)->where('path', 'mcp/add_order_note')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('call first', (string) $log->input);
        $this->assertStringContainsString('chars', (string) $log->input);
    }

    public function test_an_order_of_another_store_is_not_found(): void
    {
        [, $token] = $this->writer();
        config(['gp247-config.mcp.store_resolver' => static fn () => 'ZZ-STORE-NOTE']);
        $foreign = $this->makeMcpOrder(['store_id' => GP247_STORE_ID_ROOT]);

        $this->assertTrue($this->failed($this->callTool($token, 'add_order_note', ['order_id' => $foreign->id, 'note' => 'x'])));
        $this->assertSame(0, ShopOrderHistory::query()->where('order_id', $foreign->id)->count());
    }

    public function test_a_view_only_user_cannot_add_notes(): void
    {
        $viewer = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order,GET::' . GP247_ADMIN_PREFIX . '/order/*']);
        $order = $this->makeMcpOrder();

        $this->assertTrue($this->failed($this->callTool($this->tokenFor($viewer, ['mcp:read', 'mcp:write']), 'add_order_note', ['order_id' => $order->id, 'note' => 'x'])));
    }
}
