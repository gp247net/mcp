<?php

/**
 * @aidlc-story US-plugin-mcp-server-order-status-write
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Shop\Models\ShopOrderHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerOrderStatusWriteTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
        $this->mcpSetting('tool_update_order_status', '1');
    }

    private function writer(): array
    {
        $user = $this->mcpUser(['ANY::' . GP247_ADMIN_PREFIX . '/order/*']);

        return [$user, $this->tokenFor($user, ['mcp:read', 'mcp:write'])];
    }

    public function test_the_first_call_only_previews_and_returns_a_confirm_code(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder(['status' => 1]);

        $data = $this->structured($this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 2]));

        $this->assertFalse($data['applied']);
        $this->assertSame(1, $data['preview']['from']['id']);
        $this->assertSame(2, $data['preview']['to']['id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $data['confirm_code']);
        $this->assertSame(1, (int) $order->fresh()->status);
    }

    public function test_the_confirmed_call_changes_the_status_through_the_order_seam_with_history(): void
    {
        [$user, $token] = $this->writer();
        $order = $this->makeMcpOrder(['status' => 1]);
        $code = $this->structured($this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 2]))['confirm_code'];

        $data = $this->structured($this->callTool($token, 'update_order_status', [
            'order_id' => $order->id, 'status' => 2, 'note' => 'Packed by agent', 'confirm_code' => $code,
        ]));

        $this->assertTrue($data['applied']);
        $this->assertSame(2, (int) $order->fresh()->status);
        $history = ShopOrderHistory::query()->where('order_id', $order->id)->where('order_status_id', 2)->sole();
        $this->assertSame((string) $user->id, (string) $history->admin_id);
        $this->assertSame(2, (int) $history->order_status_id);
        $this->assertStringContainsString('Packed by agent', (string) $history->content);
    }

    public function test_a_code_is_single_use_and_bound_to_the_previewed_change(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder(['status' => 1]);
        $code = $this->structured($this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 2]))['confirm_code'];

        $other = $this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 3, 'confirm_code' => $code]);
        $this->assertTrue((bool) $other->json('result.isError'));
        $this->assertSame(1, (int) $order->fresh()->status);

        $again = $this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 2, 'confirm_code' => $code]);
        $this->assertTrue((bool) $again->json('result.isError'));
        $this->assertSame(1, (int) $order->fresh()->status);
    }

    public function test_a_code_issued_to_one_token_cannot_be_used_by_another(): void
    {
        [, $tokenA] = $this->writer();
        [, $tokenB] = $this->writer();
        $order = $this->makeMcpOrder(['status' => 1]);
        $code = $this->structured($this->callTool($tokenA, 'update_order_status', ['order_id' => $order->id, 'status' => 2]))['confirm_code'];

        $response = $this->callTool($tokenB, 'update_order_status', ['order_id' => $order->id, 'status' => 2, 'confirm_code' => $code]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame(1, (int) $order->fresh()->status);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder();

        $this->assertTrue((bool) $this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 999])->json('result.isError'));
    }

    public function test_a_user_who_may_only_view_orders_cannot_write(): void
    {
        $viewer = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order,GET::' . GP247_ADMIN_PREFIX . '/order/*']);
        $order = $this->makeMcpOrder(['status' => 1]);

        $response = $this->callTool($this->tokenFor($viewer, ['mcp:read', 'mcp:write']), 'update_order_status', ['order_id' => $order->id, 'status' => 2]);

        $this->assertTrue($this->failed($response));
        $this->assertSame(1, (int) $order->fresh()->status);
    }

    public function test_the_tool_is_announced_as_destructive_and_not_idempotent(): void
    {
        [, $token] = $this->writer();
        $tool = collect($this->mcp($token, 'tools/list')->json('result.tools'))->firstWhere('name', 'update_order_status');

        $this->assertTrue($tool['annotations']['destructiveHint'] ?? false);
        $this->assertFalse($tool['annotations']['idempotentHint'] ?? false);
        $this->assertFalse($tool['annotations']['readOnlyHint'] ?? false);
    }

    public function test_a_finalised_order_can_be_reopened_like_on_the_admin_screen_and_the_preview_says_it_is_locked(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder(['status' => 5]);

        $preview = $this->structured($this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 2]));
        $this->assertTrue($preview['preview']['locked']);

        $this->structured($this->callTool($token, 'update_order_status', ['order_id' => $order->id, 'status' => 2, 'confirm_code' => $preview['confirm_code']]));
        $this->assertSame(2, (int) $order->fresh()->status);
    }
}
