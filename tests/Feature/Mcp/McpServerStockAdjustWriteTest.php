<?php

/**
 * @aidlc-story US-plugin-mcp-server-stock-adjust-write
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerStockAdjustWriteTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
        $this->mcpSetting('tool_adjust_stock', '1');
    }

    private function writer(): array
    {
        $user = $this->mcpUser(['ANY::' . GP247_ADMIN_PREFIX . '/product', 'ANY::' . GP247_ADMIN_PREFIX . '/product/*']);

        return [$user, $this->tokenFor($user, ['mcp:read', 'mcp:write'])];
    }

    public function test_the_tool_is_off_after_install_until_the_owner_turns_it_on(): void
    {
        [, $token] = $this->writer();
        $this->mcpSetting('tool_adjust_stock', '0');

        $this->assertNotContains('adjust_stock', $this->toolNames($token));
    }

    public function test_the_preview_shows_stock_before_and_after_and_writes_nothing(): void
    {
        [, $token] = $this->writer();
        $product = $this->makeMcpProduct(['stock' => 10]);

        $data = $this->structured($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => 5]));

        $this->assertFalse($data['applied']);
        $this->assertEquals(10, $data['preview']['stock_before']);
        $this->assertEquals(15, $data['preview']['stock_after']);
        $this->assertSame($product->sku, $data['preview']['sku']);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_the_confirmed_call_applies_the_delta_without_touching_sold(): void
    {
        [, $token] = $this->writer();
        $product = $this->makeMcpProduct(['stock' => 10, 'sold' => 3]);
        $code = $this->structured($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => -4]))['confirm_code'];

        $data = $this->structured($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => -4, 'confirm_code' => $code]));

        $this->assertTrue($data['applied']);
        $this->assertEquals(6, $product->fresh()->stock);
        $this->assertEquals(3, $product->fresh()->sold);
    }

    public function test_a_change_that_would_make_stock_negative_is_refused_without_a_code(): void
    {
        [, $token] = $this->writer();
        $product = $this->makeMcpProduct(['stock' => 2]);

        $response = $this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => -3]);

        $this->assertTrue($this->failed($response));
        $this->assertNull($response->json('result.structuredContent.confirm_code'));
    }

    public function test_stock_spent_meanwhile_makes_the_confirm_refuse_and_write_nothing(): void
    {
        [, $token] = $this->writer();
        $product = $this->makeMcpProduct(['stock' => 5]);
        $code = $this->structured($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => -5]))['confirm_code'];
        $product->forceFill(['stock' => 2])->save();

        $this->assertTrue($this->failed($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => -5, 'confirm_code' => $code])));
        $this->assertEquals(2, $product->fresh()->stock);
    }

    public function test_a_code_is_bound_to_the_previewed_delta(): void
    {
        [, $token] = $this->writer();
        $product = $this->makeMcpProduct(['stock' => 10]);
        $code = $this->structured($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => 1]))['confirm_code'];

        $this->assertTrue($this->failed($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => 100, 'confirm_code' => $code])));
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_zero_and_oversized_deltas_are_refused(): void
    {
        [, $token] = $this->writer();
        $product = $this->makeMcpProduct(['stock' => 10]);

        $this->assertTrue($this->failed($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => 0])));
        $this->assertTrue($this->failed($this->callTool($token, 'adjust_stock', ['product_id' => $product->id, 'delta' => 2000000])));
    }

    public function test_a_product_of_another_store_is_not_found(): void
    {
        [, $token] = $this->writer();
        config(['gp247-config.mcp.store_resolver' => static fn () => 'ZZ-STORE-STOCK']);
        $foreign = $this->makeMcpProduct(['stock' => 10, 'store_id' => GP247_STORE_ID_ROOT]);

        $this->assertTrue($this->failed($this->callTool($token, 'adjust_stock', ['product_id' => $foreign->id, 'delta' => 1])));
    }

    public function test_a_user_who_may_only_view_products_cannot_adjust_stock(): void
    {
        $viewer = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/product,GET::' . GP247_ADMIN_PREFIX . '/product/*']);
        $product = $this->makeMcpProduct(['stock' => 10]);

        $this->assertTrue($this->failed($this->callTool($this->tokenFor($viewer, ['mcp:read', 'mcp:write']), 'adjust_stock', ['product_id' => $product->id, 'delta' => 1])));
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_the_tool_is_announced_as_destructive(): void
    {
        [, $token] = $this->writer();
        $tool = collect($this->mcp($token, 'tools/list')->json('result.tools'))->firstWhere('name', 'adjust_stock');

        $this->assertTrue($tool['annotations']['destructiveHint'] ?? false);
        $this->assertFalse($tool['annotations']['idempotentHint'] ?? true);
    }
}
