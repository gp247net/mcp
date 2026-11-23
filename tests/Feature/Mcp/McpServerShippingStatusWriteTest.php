<?php

/**
 * @aidlc-story US-plugin-mcp-server-shipping-status-write
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Shop\Models\ShopOrderHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerShippingStatusWriteTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
        $this->mcpSetting('tool_update_shipping_status', '1');
    }

    private function writer(): array
    {
        $user = $this->mcpUser(['ANY::' . GP247_ADMIN_PREFIX . '/order/*']);

        return [$user, $this->tokenFor($user, ['mcp:read', 'mcp:write'])];
    }

    public function test_the_tool_is_off_after_install_until_the_owner_turns_it_on(): void
    {
        [, $token] = $this->writer();
        $this->mcpSetting('tool_update_shipping_status', '0');

        $this->assertNotContains('update_shipping_status', $this->toolNames($token));
    }

    public function test_the_first_call_previews_the_shipping_change_and_writes_nothing(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder(['shipping_status' => 1]);

        $data = $this->structured($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 2]));

        $this->assertFalse($data['applied']);
        $this->assertSame(1, $data['preview']['from']['id']);
        $this->assertSame(2, $data['preview']['to']['id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $data['confirm_code']);
        $this->assertSame(1, (int) $order->fresh()->shipping_status);
    }

    public function test_the_confirmed_call_changes_the_shipping_status_with_an_escaped_history_note(): void
    {
        [$user, $token] = $this->writer();
        $order = $this->makeMcpOrder(['shipping_status' => 1]);
        $code = $this->structured($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 2]))['confirm_code'];

        $data = $this->structured($this->callTool($token, 'update_shipping_status', [
            'order_id' => $order->id, 'shipping_status' => 2, 'note' => 'Handed to <script>courier</script>', 'confirm_code' => $code,
        ]));

        $this->assertTrue($data['applied']);
        $this->assertSame(2, (int) $order->fresh()->shipping_status);
        $history = ShopOrderHistory::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertSame((string) $user->id, (string) $history->admin_id);
        $this->assertStringContainsString('&lt;script&gt;courier', (string) $history->content);
        $this->assertStringNotContainsString('<script>', (string) $history->content);
    }

    public function test_the_same_status_answers_no_change_without_a_code(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder(['shipping_status' => 2]);

        $data = $this->structured($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 2]));

        $this->assertFalse($data['changed']);
        $this->assertArrayNotHasKey('confirm_code', $data);
    }

    public function test_a_code_is_bound_to_the_previewed_target_and_used_once(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder(['shipping_status' => 1]);
        $code = $this->structured($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 2]))['confirm_code'];

        $this->assertTrue($this->failed($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 3, 'confirm_code' => $code])));
        $this->assertTrue($this->failed($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 2, 'confirm_code' => $code])));
        $this->assertSame(1, (int) $order->fresh()->shipping_status);
    }

    public function test_a_confirm_after_someone_already_set_the_target_reports_no_change(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder(['shipping_status' => 1]);
        $code = $this->structured($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 2]))['confirm_code'];
        $order->forceFill(['shipping_status' => 2])->save();
        $before = ShopOrderHistory::query()->where('order_id', $order->id)->count();

        $data = $this->structured($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 2, 'confirm_code' => $code]));

        $this->assertFalse($data['applied']);
        $this->assertFalse($data['changed']);
        $this->assertSame($before, ShopOrderHistory::query()->where('order_id', $order->id)->count());
    }

    public function test_an_unknown_shipping_status_is_refused(): void
    {
        [, $token] = $this->writer();
        $order = $this->makeMcpOrder(['shipping_status' => 1]);

        $this->assertTrue($this->failed($this->callTool($token, 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 999])));
    }

    public function test_a_read_token_and_a_view_only_user_cannot_change_shipping(): void
    {
        [$user] = $this->writer();
        $viewer = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order,GET::' . GP247_ADMIN_PREFIX . '/order/*']);
        $order = $this->makeMcpOrder(['shipping_status' => 1]);

        $this->assertNotContains('update_shipping_status', $this->toolNames($this->tokenFor($user, ['mcp:read'])));
        $this->assertTrue($this->failed($this->callTool($this->tokenFor($viewer, ['mcp:read', 'mcp:write']), 'update_shipping_status', ['order_id' => $order->id, 'shipping_status' => 2])));
    }

    public function test_the_tool_is_announced_as_idempotent_and_not_destructive(): void
    {
        [, $token] = $this->writer();
        $tool = collect($this->mcp($token, 'tools/list')->json('result.tools'))->firstWhere('name', 'update_shipping_status');

        $this->assertFalse($tool['annotations']['readOnlyHint'] ?? true);
        $this->assertFalse($tool['annotations']['destructiveHint'] ?? true);
        $this->assertTrue($tool['annotations']['idempotentHint'] ?? false);
    }
}
