<?php

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Front\Library\SeoContentMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

/**
 * The site owner's agent reads the SEO check and a product's SEO, and edits it with the
 * same rights as on the screens — confirming any change to whether a page can appear in
 * search results.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-seo-read, US-plugin-mcp-server-seo-write
 * @aidlc-adr ADR-seo-audit, plugin-mcp-server_write-tool-policy
 */
class McpServerSeoToolsTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
    }

    private function reader(bool $productRight = true): string
    {
        $uris = ['GET::' . GP247_ADMIN_PREFIX . '/seo_audit'];
        if ($productRight) {
            $uris[] = 'GET::' . GP247_ADMIN_PREFIX . '/product';
        }
        $user = $this->mcpUser($uris);

        return $this->tokenFor($user, ['mcp:read']);
    }

    private function writer(bool $productRight = true): string
    {
        $uris = ['ANY::' . GP247_ADMIN_PREFIX . '/seo_audit'];
        if ($productRight) {
            $uris[] = 'ANY::' . GP247_ADMIN_PREFIX . '/product';
            $uris[] = 'ANY::' . GP247_ADMIN_PREFIX . '/product/*';
        }
        $this->mcpSetting('tool_update_seo', '1');

        return $this->tokenFor($this->mcpUser($uris), ['mcp:read', 'mcp:write']);
    }

    public function test_the_read_tools_are_listed_and_update_seo_is_off_until_turned_on(): void
    {
        $names = $this->toolNames($this->writer());
        $this->assertContains('seo_audit', $names);
        $this->assertContains('get_seo', $names);
        $this->assertContains('update_seo', $names);

        $token = $this->writer();
        $this->mcpSetting('tool_update_seo', '0');
        $this->assertNotContains('update_seo', $this->toolNames($token));
    }

    public function test_seo_audit_returns_the_findings_of_the_store(): void
    {
        $product = $this->makeMcpProduct();
        SeoContentMeta::save('product', $product->id, ['noindex' => true]);

        $data = $this->structured($this->callTool($this->reader(), 'seo_audit', ['refresh' => true]));

        $this->assertArrayHasKey('findings', $data);
        $this->assertArrayHasKey('content_noindex', $data['findings']);
        $this->assertArrayHasKey('checked_at', $data);
    }

    public function test_get_seo_returns_own_and_shown_values(): void
    {
        $product = $this->makeMcpProduct();
        $lang = array_key_first(\GP247\Core\Models\AdminLanguage::getListActive()->all());
        SeoContentMeta::save('product', $product->id, ['nofollow' => true, 'desc' => [$lang => ['title' => 'ZZ MCP SEO title']]]);

        $data = $this->structured($this->callTool($this->reader(), 'get_seo', ['type' => 'product', 'id' => $product->id]));

        $this->assertTrue($data['own']['nofollow']);
        $this->assertSame('ZZ MCP SEO title', $data['own']['desc'][$lang]['title']);
        $this->assertSame('ZZ MCP SEO title', $data['shown'][$lang]['title']);
        $this->assertSame('index, nofollow', $data['shown'][$lang]['robots']);
    }

    public function test_a_second_refresh_within_the_cooldown_returns_the_kept_result(): void
    {
        $token = $this->reader();
        $first = $this->structured($this->callTool($token, 'seo_audit', ['refresh' => true]));
        $second = $this->structured($this->callTool($token, 'seo_audit', ['refresh' => true]));

        $this->assertTrue($first['refreshed']);
        $this->assertFalse($second['refreshed']);
        $this->assertSame($first['checked_at'], $second['checked_at']);
        $this->assertGreaterThan(0, $second['retry_after_seconds']);
    }

    public function test_get_seo_refuses_a_reader_without_the_product_screen_right(): void
    {
        $product = $this->makeMcpProduct();

        $this->assertTrue($this->failed($this->callTool($this->reader(productRight: false), 'get_seo', ['type' => 'product', 'id' => $product->id])));
    }

    public function test_get_seo_refuses_an_unknown_type(): void
    {
        $this->assertTrue($this->failed($this->callTool($this->reader(), 'get_seo', ['type' => 'zz_unknown', 'id' => 'x'])));
    }

    public function test_changing_only_title_and_description_applies_at_once(): void
    {
        $product = $this->makeMcpProduct();
        $lang = array_key_first(\GP247\Core\Models\AdminLanguage::getListActive()->all());

        $data = $this->structured($this->callTool($this->writer(), 'update_seo', [
            'type' => 'product', 'id' => $product->id, 'lang' => $lang, 'title' => 'ZZ Agent title', 'description' => 'ZZ Agent description',
        ]));

        $this->assertTrue($data['applied']);
        $saved = SeoContentMeta::for('product', $product->id, $lang);
        $this->assertSame('ZZ Agent title', $saved['title']);
        $this->assertSame('ZZ Agent description', $saved['description']);
    }

    public function test_noindex_needs_a_confirm_code_and_writes_nothing_before_it(): void
    {
        $token = $this->writer();
        $product = $this->makeMcpProduct();
        $args = ['type' => 'product', 'id' => $product->id, 'noindex' => true];

        $preview = $this->structured($this->callTool($token, 'update_seo', $args));
        $this->assertFalse($preview['applied']);
        $this->assertNotEmpty($preview['confirm_code']);
        $this->assertNull(SeoContentMeta::for('product', $product->id));

        $done = $this->structured($this->callTool($token, 'update_seo', $args + ['confirm_code' => $preview['confirm_code']]));
        $this->assertTrue($done['applied']);
        $this->assertTrue(SeoContentMeta::for('product', $product->id)['noindex']);
    }

    public function test_a_null_flag_counts_as_not_given(): void
    {
        $product = $this->makeMcpProduct();
        $lang = array_key_first(\GP247\Core\Models\AdminLanguage::getListActive()->all());
        SeoContentMeta::save('product', $product->id, ['noindex' => true]);

        $data = $this->structured($this->callTool($this->writer(), 'update_seo', [
            'type' => 'product', 'id' => $product->id, 'lang' => $lang, 'title' => 'ZZ Null flag', 'noindex' => null, 'nofollow' => null,
        ]));

        $this->assertTrue($data['applied']);
        $this->assertTrue(SeoContentMeta::for('product', $product->id, $lang)['noindex']);
    }

    public function test_a_writer_without_the_product_screen_right_is_refused(): void
    {
        $product = $this->makeMcpProduct();

        $response = $this->callTool($this->writer(productRight: false), 'update_seo', ['type' => 'product', 'id' => $product->id, 'title' => 'ZZ No right']);

        $this->assertTrue($this->failed($response));
        $this->assertNull(SeoContentMeta::for('product', $product->id));
    }

    public function test_a_canonical_that_is_not_a_web_address_is_refused(): void
    {
        $product = $this->makeMcpProduct();
        $lang = array_key_first(\GP247\Core\Models\AdminLanguage::getListActive()->all());

        $response = $this->callTool($this->writer(), 'update_seo', ['type' => 'product', 'id' => $product->id, 'lang' => $lang, 'canonical' => 'javascript:alert(1)', 'confirm_code' => 'x']);

        $this->assertTrue($this->failed($response));
    }

    public function test_content_of_another_store_is_not_found(): void
    {
        $product = $this->makeMcpProduct(['store_id' => 'ZZ-OTHER-STORE']);

        $this->assertTrue($this->failed($this->callTool($this->reader(), 'get_seo', ['type' => 'product', 'id' => $product->id])));
    }
}
