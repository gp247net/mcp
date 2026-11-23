<?php

namespace GP247\Mcp\Tests\Unit\Mcp;

use GP247\Mcp\Services\ConfirmationService;
use GP247\Mcp\Services\McpToken;
use GP247\Mcp\Services\PiiMasker;
use GP247\Mcp\Tools\ToolCatalog;
use GP247\Mcp\Tools\ToolDefinition;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Pure building blocks of the McpServer plugin (no database).
 */
class McpServerUnitsTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    // --- PiiMasker ----------------------------------------------------------------

    public function test_contact_details_are_masked_by_default(): void
    {
        $masker = new PiiMasker();

        $this->assertSame('n***@g***.com', $masker->email('nguyen@gmail.com'));
        $this->assertSame('***789', $masker->phone('090-123-4789'));
        $this->assertSame('***', $masker->address('12 Secret Street'));
        $this->assertSame('', $masker->address(''));
        $this->assertNull($masker->email(null));
    }

    public function test_contact_details_pass_through_when_revealed(): void
    {
        $masker = new PiiMasker(true);

        $this->assertSame('nguyen@gmail.com', $masker->email('nguyen@gmail.com'));
        $this->assertSame('0901234789', $masker->phone('0901234789'));
    }

    public function test_scrub_masks_emails_and_long_digit_runs_in_free_text(): void
    {
        $scrubbed = PiiMasker::scrub('call 0901234789 or mail a.b@shop.vn, order 12345');

        $this->assertStringNotContainsString('0901234789', $scrubbed);
        $this->assertStringNotContainsString('a.b@shop.vn', $scrubbed);
        $this->assertStringContainsString('12345', $scrubbed);
    }

    // --- ConfirmationService ----------------------------------------------------------

    private function useArrayCache(): void
    {
        Cache::swap(new Repository(new ArrayStore()));
    }

    public function test_a_confirmation_code_is_single_use_and_bound_to_token_tool_and_arguments(): void
    {
        $this->useArrayCache();
        $service = new ConfirmationService();
        $args = ['order_id' => 'OR1', 'status' => 2];

        $code = $service->issue(7, 'update_order_status', $args);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $code);
        $this->assertFalse($service->consume($code, 8, 'update_order_status', $args));
        $code = $service->issue(7, 'update_order_status', $args);
        $this->assertFalse($service->consume($code, 7, 'update_order_status', ['order_id' => 'OR1', 'status' => 3]));
        $code = $service->issue(7, 'update_order_status', $args);
        $this->assertTrue($service->consume($code, 7, 'update_order_status', ['status' => 2, 'order_id' => 'OR1']));
        $this->assertFalse($service->consume($code, 7, 'update_order_status', $args));
    }

    public function test_a_malformed_code_is_refused_without_touching_the_cache(): void
    {
        $this->useArrayCache();

        $this->assertFalse((new ConfirmationService())->consume("' OR 1=1 --", 7, 'update_order_status', []));
    }

    // --- McpToken ------------------------------------------------------------------------

    private function token(array $abilities): PersonalAccessToken
    {
        $token = new PersonalAccessToken();
        $token->abilities = $abilities;

        return $token;
    }

    public function test_only_explicit_mcp_abilities_count_never_the_wildcard(): void
    {
        $this->assertFalse(McpToken::isMcp($this->token(['*'])));
        $this->assertFalse(McpToken::canWrite($this->token(['*'])));
        $this->assertTrue(McpToken::isMcp($this->token(['mcp:read'])));
        $this->assertFalse(McpToken::canWrite($this->token(['mcp:read'])));
        $this->assertTrue(McpToken::canWrite($this->token(['mcp:read', 'mcp:write'])));
        $this->assertFalse(McpToken::isMcp(null));
    }

    public function test_the_tool_allowlist_is_read_from_the_abilities(): void
    {
        $this->assertSame([], McpToken::allowedTools($this->token(['mcp:read'])));
        $this->assertSame(['get_order', 'store_info'], McpToken::allowedTools($this->token(['mcp:read', 'mcp:tool:get_order', 'mcp:tool:store_info'])));
    }

    // --- ToolCatalog / ToolDefinition ----------------------------------------------------

    public function test_the_catalog_has_thirteen_read_tools_and_five_write_tools_off_by_default(): void
    {
        $tools = ToolCatalog::all();

        $this->assertCount(18, $tools);
        $writes = array_filter($tools, fn (ToolDefinition $t) => $t->isWrite());
        $this->assertSame(
            ['update_order_status', 'update_shipping_status', 'add_order_note', 'adjust_stock', 'update_seo'],
            array_keys($writes)
        );
        foreach ($writes as $write) {
            $this->assertFalse($write->defaultEnabled, $write->name);
        }

        // WHY: read tools are on by default (US-plugin-mcp-server-seo-read: "Bật mặc định").
        $reads = array_filter($tools, fn (ToolDefinition $t) => !$t->isWrite());
        $this->assertCount(13, $reads);
        foreach ($reads as $read) {
            $this->assertTrue($read->defaultEnabled, $read->name);
        }
        foreach (['seo_audit', 'get_seo', 'update_seo'] as $seoTool) {
            $this->assertNotNull(ToolCatalog::find($seoTool), $seoTool);
        }
        $this->assertNull(ToolCatalog::find('drop_database'));
    }

    public function test_a_tool_whose_seam_is_missing_is_unavailable(): void
    {
        $present = new ToolDefinition('x', ToolDefinition::READ, null, true, [ToolDefinition::class . '::isWrite', '\\' . ToolDefinition::class]);
        $noMethod = new ToolDefinition('x', ToolDefinition::READ, null, true, [ToolDefinition::class . '::noSuchSeam']);
        $noClass = new ToolDefinition('x', ToolDefinition::READ, null, true, ['GP247\\Nowhere\\Seam']);

        $this->assertTrue($present->available());
        $this->assertFalse($noMethod->available());
        $this->assertFalse($noClass->available());
    }

    public function test_the_event_tool_has_no_single_screen(): void
    {
        $tool = ToolCatalog::get('recent_changes');

        $this->assertTrue($tool->usesEventScreens());
        $this->assertNull($tool->screenUri());
    }

    /**
     * WHY a separate process: the admin prefix is a process-wide constant that gp247/core defines
     * when the application boots; defining it here would make every later test that boots the
     * application fail in the same PHPUnit run (the public suite runs Unit and Feature together).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_screen_uris_use_the_admin_prefix_and_the_record_id(): void
    {
        if (!defined('GP247_ADMIN_PREFIX')) {
            define('GP247_ADMIN_PREFIX', 'gp247_admin');
        }

        $this->assertSame(GP247_ADMIN_PREFIX . '/order/detail/OR9', ToolCatalog::get('update_order_status')->screenUri('OR9'));
        $this->assertSame(GP247_ADMIN_PREFIX . '/product', ToolCatalog::get('search_products')->screenUri());
        $this->assertNull(ToolCatalog::get('store_info')->screenUri());
    }
}
