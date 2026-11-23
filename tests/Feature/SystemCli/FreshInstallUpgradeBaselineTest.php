<?php

namespace GP247\Mcp\Tests\Feature\SystemCli;

use GP247\Core\Support\UpgradeMigrationBaseline;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Mcp\Tests\TestCase;

/**
 * A site installed fresh records its packages' upgrade migrations as already run: the base
 * create-tables migration and the seeders already produced what they would, so the first
 * `gp247:update` must not run them again. Files shipped later still run.
 *
 * Only DML on the `migrations` table inside the test transaction; the real install commands
 * drop tables, so their wiring is checked in the source and the end-to-end check runs in
 * FreshInstallUpgradeEquivalenceTest on its own database.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-fresh-install-upgrade-baseline
 * @aidlc-adr compat-foundation_public-release-migration-policy
 */
class FreshInstallUpgradeBaselineTest extends TestCase
{
    use DatabaseTransactions;

    /** Install command source => its package's upgrade/ folder, relative to the command folder. */
    private const INSTALLERS = [
        'vendor/gp247/core/src/Commands/Install.php' => "dirname(__DIR__) . '/Database/Migrations/upgrade'",
        'vendor/gp247/front/src/Commands/FrontInstall.php' => "dirname(__DIR__) . '/Admin/Database/Migrations/upgrade'",
        'vendor/gp247/shop/src/Commands/ShopInstall.php' => "dirname(__DIR__) . '/Admin/Database/Migrations/upgrade'",
    ];

    /** @var string Temporary folder holding fake upgrade migrations. */
    private string $dir;

    /** @var string Unique part of the fake migration names of this run. */
    private string $run;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->run = strtolower(bin2hex(random_bytes(4)));
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gp247-baseline-' . $this->run;
        mkdir($this->dir);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * Write a fake upgrade migration that fails the test if it is ever executed.
     *
     * @param string $slug Name part after the date.
     * @return string Migration name (file name without .php).
     */
    private function fakeMigration(string $slug): string
    {
        $name = '2099_01_01_000000_' . $slug . '_' . $this->run;
        file_put_contents(
            $this->dir . DIRECTORY_SEPARATOR . $name . '.php',
            "<?php\nthrow new \\RuntimeException('an upgrade migration must not be executed by the baseline');\n"
        );
        return $name;
    }

    /**
     * @param string $name Migration name.
     * @return object|null Row of the migrations table.
     */
    private function row(string $name)
    {
        return DB::table('migrations')->where('migration', $name)->first();
    }

    public function test_every_upgrade_migration_of_a_fresh_install_is_recorded_as_run_without_running_it(): void
    {
        $first = $this->fakeMigration('add_thing');
        $second = $this->fakeMigration('seed_other_thing');
        $nextBatch = (int) DB::table('migrations')->max('batch') + 1;

        $recorded = UpgradeMigrationBaseline::markApplied($this->dir);

        $this->assertSame([$first, $second], $recorded);
        $this->assertSame($nextBatch, (int) $this->row($first)->batch);
        $this->assertSame($nextBatch, (int) $this->row($second)->batch);
    }

    public function test_a_migration_already_recorded_keeps_its_row_and_is_not_recorded_twice(): void
    {
        $old = $this->fakeMigration('already_run');
        DB::table('migrations')->insert(['migration' => $old, 'batch' => 1]);
        $new = $this->fakeMigration('not_yet_run');

        $recorded = UpgradeMigrationBaseline::markApplied($this->dir);

        $this->assertSame([$new], $recorded);
        $this->assertSame(1, DB::table('migrations')->where('migration', $old)->count());
        $this->assertSame(1, (int) $this->row($old)->batch);

        $this->assertSame([], UpgradeMigrationBaseline::markApplied($this->dir), 'installing again records nothing new');
        $this->assertSame(1, DB::table('migrations')->where('migration', $new)->count());
    }

    public function test_a_migration_shipped_in_a_later_version_is_still_pending_after_install(): void
    {
        $this->fakeMigration('shipped_with_this_version');
        UpgradeMigrationBaseline::markApplied($this->dir);

        $later = $this->fakeMigration('shipped_later');

        $this->assertNull($this->row($later), 'a file the install did not see has no row, so gp247:update runs it');
    }

    public function test_a_missing_or_empty_folder_records_nothing(): void
    {
        $this->assertSame([], UpgradeMigrationBaseline::markApplied($this->dir));
        $this->assertSame([], UpgradeMigrationBaseline::markApplied($this->dir . DIRECTORY_SEPARATOR . 'missing'));
    }

    public function test_each_install_command_records_only_its_own_package_upgrade_folder(): void
    {
        // WHY the folder is taken from the command's own location: a package can only reach
        // its own upgrade/ folder that way, never another package's.
        foreach (self::INSTALLERS as $source => $folder) {
            $code = (string) file_get_contents(base_path($source));
            $this->assertStringContainsString('UpgradeMigrationBaseline::markApplied(' . $folder . ')', $code, $source . ' must record its own upgrade/ folder');
        }
        $mcp = (string) file_get_contents(base_path('vendor/gp247/mcp/src/Commands/McpInstall.php'));
        $this->assertStringNotContainsString('UpgradeMigrationBaseline', $mcp, 'gp247/mcp is out of scope (US-CLI-fresh-install-upgrade-baseline)');
    }
}
