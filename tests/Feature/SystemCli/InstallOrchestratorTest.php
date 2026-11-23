<?php

namespace GP247\Mcp\Tests\Feature\SystemCli;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use GP247\Mcp\Tests\TestCase;

/**
 * Verifies the gp247:install orchestrator (US-CLI-003):
 * auto-detection of present packages, the default confirmation gate that
 * refuses destructive unattended installs, and the deprecated --with-* no-ops.
 *
 * These tests exercise ONLY the pre-execution gate (they always run without
 * --force, so respondFailure('confirmation_required') returns before any
 * destructive step runs) — no packages are actually (re)installed.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-story US-plugin-mcp-server-package-install
 * @aidlc-adr system-cli_command-registration-tiers
 */
class InstallOrchestratorTest extends TestCase
{
    /**
     * Run a gp247 command in JSON mode and decode its single envelope.
     *
     * @param string               $command
     * @param array<string, mixed> $args
     * @return array{code: int, data: array}
     */
    protected function runJson(string $command, array $args = []): array
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call($command, array_merge($args, ['--json' => true]), $buffer);
        return ['code' => $code, 'data' => json_decode(trim($buffer->fetch()), true) ?: []];
    }

    /**
     * In --json (non-interactive) mode without --force, install must refuse and
     * run no steps.
     *
     * @return void
     */
    public function test_confirmation_gate_blocks_unattended_install(): void
    {
        $res = $this->runJson('gp247:install');

        $this->assertFalse($res['data']['ok']);
        $this->assertSame('confirmation_required', $res['data']['error']['code']);
        $this->assertNotSame(0, $res['code']);
    }

    /**
     * Auto-detection includes front/shop install steps when those commands are
     * registered (they are in this full test install). The detected plan is
     * surfaced in the confirmation_required payload without executing anything.
     *
     * @return void
     */
    public function test_autodetect_includes_present_packages(): void
    {
        $res = $this->runJson('gp247:install');
        $detected = $res['data']['data']['detected'] ?? [];

        $this->assertContains('gp247:core-install', $detected);

        $names = array_keys(Artisan::all());
        if (in_array('gp247:front-install', $names, true)) {
            $this->assertContains('gp247:front-install', $detected);
        }
        if (in_array('gp247:shop-install', $names, true)) {
            $this->assertContains('gp247:shop-install', $detected);
        }
        // --sample not passed, so shop-sample must never be planned.
        $this->assertNotContains('gp247:shop-sample', $detected);
    }

    /**
     * The old opt-in flags were removed entirely (package selection is
     * auto-detected). The command must expose neither --with-front nor
     * --with-shop, so callers cannot rely on them.
     *
     * @return void
     */
    public function test_with_flags_are_not_defined(): void
    {
        $definition = Artisan::all()['gp247:install']->getDefinition();

        $this->assertFalse($definition->hasOption('with-front'), '--with-front must be removed');
        $this->assertFalse($definition->hasOption('with-shop'), '--with-shop must be removed');
    }

    /**
     * The sample step boots the freshly installed site in a new process: in the
     * install process the gp247_* helpers are not loaded (they load at boot only
     * once gp247-installed.txt exists), so shop-sample failed on
     * gp247_generate_id(). Not run for real here — it wipes
     * the shop catalog.
     *
     * @return void
     */
    public function test_the_sample_step_runs_in_a_fresh_process_so_it_sees_the_installed_site(): void
    {
        $steps = (new \ReflectionClassConstant(\GP247\Core\Commands\InstallAll::class, 'FRESH_PROCESS_STEPS'))->getValue();

        $this->assertContains('gp247:shop-sample', $steps);
    }

    /**
     * shop-sample empties the catalog BEFORE opening its transaction: MySQL TRUNCATE
     * commits implicitly, so inside the transaction it ended it and the final commit
     * failed with "There is no active transaction" (exit 1 after writing the data).
     *
     * @return void
     */
    public function test_the_shop_sample_empties_the_catalog_outside_its_transaction(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(\GP247\Shop\Commands\ShopSample::class))->getFileName());
        $handle = substr($source, strpos($source, 'function handleGp247'), 1200);

        $this->assertLessThan(strpos($handle, '->transaction('), strpos($handle, '$this->clearCatalog();'), 'the clear phase runs before the transaction opens');
        $seed = substr($source, strpos($source, 'private function seed()'));
        $this->assertStringNotContainsString('->truncate()', $seed, 'no TRUNCATE inside the seeding transaction');
    }

    /**
     * shop-sample is an electronics store whose 36 single products each show one hosted photo:
     * every photo is used exactly once, inside the subcategory that matches it, every product is
     * named in vi/en/ja, and the subcategories E2E journeys rely on (SAMPLE-0-1, SAMPLE-2-1) exist.
     *
     * @aidlc-story US-CLI-005
     * @return void
     */
    public function test_the_shop_sample_shows_every_product_photo_once_in_its_matching_category(): void
    {
        $categories = \GP247\Shop\Commands\Sample\ShopSampleCatalog::categories();
        $leaves = array_merge(...array_column($categories, 'children'));

        $productImages = [];
        foreach ($leaves as $leaf) {
            foreach ($leaf['products'] as $product) {
                $productImages[] = $product['image'];
                foreach (['vi', 'en', 'ja'] as $lang) {
                    $this->assertNotSame('', trim($product['names'][$lang] ?? ''), "product photo {$product['image']} is named in {$lang}");
                }
            }
        }
        sort($productImages);
        $this->assertSame(
            range(1, \GP247\Shop\Commands\Sample\ShopSampleCatalog::PRODUCT_IMAGE_COUNT),
            $productImages,
            'every hosted product photo is used exactly once'
        );

        $leafImages = array_column($leaves, 'image');
        sort($leafImages);
        $this->assertSame(range(1, \GP247\Shop\Commands\Sample\ShopSampleCatalog::CATALOG_IMAGE_COUNT), $leafImages, 'each subcategory has its own category photo');

        $aliases = array_merge(array_column($categories, 'alias'), array_column($leaves, 'alias'));
        $this->assertSame($aliases, array_unique($aliases), 'category aliases are unique');
        foreach (array_merge($categories, $leaves) as $category) {
            foreach (['vi', 'en', 'ja'] as $lang) {
                $this->assertNotSame('', trim($category['descriptions'][$lang]['name'] ?? ''), "category {$category['alias']} is named in {$lang}");
            }
        }

        $this->assertNotEmpty($leaves[0]['products'], 'SAMPLE-0-1 exists');
        $this->assertNotEmpty($leaves[2]['products'], 'SAMPLE-2-1 exists');
        $this->assertStringNotContainsString(
            'picsum.photos',
            (string) file_get_contents((new \ReflectionClass(\GP247\Shop\Commands\ShopSample::class))->getFileName()),
            'no sample row keeps a placeholder photo'
        );
    }

    /**
     * shop-sample brands and suppliers carry the names on their hosted logos, never a real
     * trademark, and the command builds the hosted photo URLs of every kind.
     *
     * @aidlc-story US-CLI-005
     * @return void
     */
    public function test_the_shop_sample_brands_and_suppliers_match_their_logos(): void
    {
        $brands = \GP247\Shop\Commands\Sample\ShopSampleCatalog::brands();
        $suppliers = \GP247\Shop\Commands\Sample\ShopSampleCatalog::suppliers();

        $this->assertSame(['Voltiq', 'Aurion', 'Nexora'], array_column($brands, 'name'));
        $this->assertSame([1, 2, 3], array_column($brands, 'image'));
        $this->assertSame(['Lumora Distribution', 'Techvanta Supply Chain', 'Orbiqa Trading Co.'], array_column($suppliers, 'name'));
        $this->assertSame([1, 2, 3], array_column($suppliers, 'image'));
        foreach (array_merge($brands, $suppliers) as $row) {
            $this->assertStringEndsWith('.example', $row['url'], 'sample links use the reserved .example domain');
        }

        $command = new \GP247\Shop\Commands\ShopSample();
        $product = (new \ReflectionMethod($command, 'productImage'))->getClosure($command);
        $catalog = (new \ReflectionMethod($command, 'catalogImage'))->getClosure($command);
        $this->assertSame('https://static.gp247.net/file/s-cart/product/product-36.png', $product(36));
        $this->assertSame('https://static.gp247.net/file/s-cart/cate/cate-10.png', $catalog('cate', 10));
        $this->assertSame('https://static.gp247.net/file/s-cart/supplier/supplier-1.png', $catalog('supplier', 1));
    }

    /**
     * gp247/mcp ships with S-Cart: when its install command is registered the orchestrator plans
     * it right after the shop (mcp requires the shop) and before the sample data.
     *
     * @return void
     */
    public function test_autodetect_plans_the_mcp_install_after_the_shop(): void
    {
        if (!in_array('gp247:mcp-install', array_keys(Artisan::all()), true)) {
            $this->markTestSkipped('gp247/mcp is not installed on this site.');
        }

        $detected = $this->runJson('gp247:install')['data']['data']['detected'] ?? [];

        $this->assertContains('gp247:mcp-install', $detected);
        $this->assertGreaterThan(array_search('gp247:shop-install', $detected, true), array_search('gp247:mcp-install', $detected, true));
    }

    /**
     * The MCP install step owns no table, so the confirmation gate never lists it among the
     * destructive steps.
     *
     * @return void
     */
    public function test_the_mcp_install_step_is_not_flagged_as_destructive(): void
    {
        $source = (string) file_get_contents(base_path('vendor/gp247/core/src/Commands/InstallAll.php'));
        $line = substr($source, strpos($source, '$destructive = array_intersect('));
        $line = substr($line, 0, strpos($line, ';'));

        $this->assertStringNotContainsString('mcp', $line);
    }
}
