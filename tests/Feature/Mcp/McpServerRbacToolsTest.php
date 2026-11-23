<?php

/**
 * @aidlc-story US-plugin-mcp-server-rbac-tools, US-plugin-mcp-server-seo-read
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Core\Models\AdminPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerRbacToolsTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    private const READ_TOOLS = [
        'get_customer', 'get_order', 'get_product', 'get_seo', 'list_categories', 'low_stock_products',
        'recent_changes', 'sales_summary', 'search_customers', 'search_orders', 'search_products', 'seo_audit', 'store_info',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
    }

    public function test_an_administrator_read_token_sees_every_read_tool_and_no_write_tool(): void
    {
        $this->assertSame(self::READ_TOOLS, $this->toolNames($this->tokenFor($this->administrator())));
    }

    public function test_write_tools_appear_only_when_enabled_on_the_site_and_the_token_may_write(): void
    {
        $admin = $this->administrator();
        $writeToken = $this->tokenFor($admin, ['mcp:read', 'mcp:write']);
        $this->assertNotContains('update_order_status', $this->toolNames($writeToken));

        $this->mcpSetting('tool_update_order_status', '1');

        $this->assertContains('update_order_status', $this->toolNames($writeToken));
        $this->assertNotContains('update_order_status', $this->toolNames($this->tokenFor($admin)));
    }

    public function test_a_staff_member_only_gets_the_tools_of_the_screens_they_may_open(): void
    {
        $orderViewer = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order,GET::' . GP247_ADMIN_PREFIX . '/order/*']);

        // WHY recent_changes: order events are readable by whoever may open the order screen (1.1.0).
        $this->assertSame(['get_order', 'recent_changes', 'search_orders', 'store_info'], $this->toolNames($this->tokenFor($orderViewer)));
    }

    public function test_a_tool_the_user_may_not_use_cannot_be_called_even_by_name(): void
    {
        $orderViewer = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order']);

        $response = $this->callTool($this->tokenFor($orderViewer), 'search_customers');

        $this->assertTrue($this->failed($response), (string) $response->getContent());
        $this->assertStringContainsString('not found', (string) $response->json('error.message'));
    }

    public function test_an_admin_without_the_mcp_permission_cannot_use_a_token_even_if_one_exists(): void
    {
        $noMcp = $this->makeAdmin(['ANY::' . GP247_ADMIN_PREFIX . '/order/*']);

        $this->mcp($this->tokenFor($noMcp), 'tools/list')->assertStatus(403);
    }

    public function test_a_read_token_cannot_call_the_write_tool_even_for_an_administrator(): void
    {
        $this->mcpSetting('tool_update_order_status', '1');
        $order = $this->makeMcpOrder();

        $response = $this->callTool($this->tokenFor($this->administrator()), 'update_order_status', ['order_id' => $order->id, 'status' => 2]);

        $this->assertTrue($this->failed($response));
        $this->assertSame(1, (int) $order->fresh()->status);
    }

    public function test_a_token_allowlist_limits_tools_even_for_an_administrator(): void
    {
        $token = $this->tokenFor($this->administrator(), ['mcp:read', 'mcp:tool:sales_summary']);

        $this->assertSame(['sales_summary'], $this->toolNames($token));
        $this->assertTrue($this->failed($this->callTool($token, 'search_orders')));
    }

    public function test_a_tool_disabled_on_the_site_disappears_for_everyone(): void
    {
        $this->mcpSetting('tool_search_customers', '0');

        $this->assertNotContains('search_customers', $this->toolNames($this->tokenFor($this->administrator())));
    }

    public function test_a_permission_change_takes_effect_on_the_next_request(): void
    {
        $user = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order']);
        $token = $this->tokenFor($user);
        $this->assertContains('search_orders', $this->toolNames($token));

        AdminPermission::query()->whereIn('id', $user->permissions()->pluck('id'))
            ->where('http_uri', 'GET::' . GP247_ADMIN_PREFIX . '/order')
            ->update(['http_uri' => 'GET::' . GP247_ADMIN_PREFIX . '/customer']);

        $names = $this->toolNames($token);
        $this->assertNotContains('search_orders', $names);
        $this->assertContains('search_customers', $names);
    }

    public function test_a_view_all_user_reads_but_never_writes(): void
    {
        $this->mcpSetting('tool_update_order_status', '1');
        $viewer = $this->makeAdmin(['ANY::' . GP247_ADMIN_PREFIX . '/mcp/tokens'], ['view.all']);
        $names = $this->toolNames($this->tokenFor($viewer, ['mcp:read', 'mcp:write']));

        $this->assertContains('search_orders', $names);
        $this->assertNotContains('update_order_status', $names);
    }

    public function test_tool_descriptions_are_static_text_without_shop_data(): void
    {
        $this->makeMcpProduct(['sku' => 'ZZ-LEAK-SKU']);
        $response = $this->mcp($this->tokenFor($this->administrator()), 'tools/list');

        $this->assertStringNotContainsString('ZZ-LEAK-SKU', (string) $response->getContent());
    }
}
