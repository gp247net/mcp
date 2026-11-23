<?php

/**
 * @aidlc-story US-plugin-mcp-server-recent-changes-read
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerRecentChangesTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
        $this->mcpApiSwitch('api_event_log', true);
    }

    /** Insert one event row (rolled back with the test). */
    private function event(string $type, string $storeId, ?\DateTimeInterface $at = null): int
    {
        return (int) DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event')->insertGetId([
            'type' => $type,
            'subject_id' => 'zz-' . Str::lower(Str::random(8)),
            'store_id' => $storeId,
            'source' => 'admin',
            'actor_type' => null,
            'actor_id' => null,
            'data' => json_encode(['status' => 1]),
            'occurred_at' => $at ?? now()->subMinutes(10),
        ]);
    }

    /** @return int[] Ids of the returned events. */
    private function ids(array $data): array
    {
        return array_column($data['events'], 'id');
    }

    public function test_an_owner_reads_the_changes_of_the_last_day_oldest_first(): void
    {
        $token = $this->tokenFor($this->administrator());
        $old = $this->event('order.created', GP247_STORE_ID_ROOT, now()->subDays(3));
        $first = $this->event('order.created', GP247_STORE_ID_ROOT, now()->subHours(2));
        $second = $this->event('stock.changed', GP247_STORE_ID_ROOT, now()->subHour());

        $data = $this->structured($this->callTool($token, 'recent_changes', []));

        $ids = $this->ids($data);
        $this->assertTrue($data['enabled']);
        $this->assertNotContains($old, $ids);
        $this->assertLessThan(array_search($second, $ids, true), array_search($first, $ids, true));
    }

    public function test_events_younger_than_the_settle_window_are_held_back(): void
    {
        $token = $this->tokenFor($this->administrator());
        $fresh = $this->event('order.created', GP247_STORE_ID_ROOT, now());

        $this->assertNotContains($fresh, $this->ids($this->structured($this->callTool($token, 'recent_changes', []))));
    }

    public function test_only_types_of_screens_the_owner_may_open_are_returned(): void
    {
        $orderStaff = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order', 'GET::' . GP247_ADMIN_PREFIX . '/order/*']);
        $order = $this->event('order.created', GP247_STORE_ID_ROOT);
        $stock = $this->event('stock.changed', GP247_STORE_ID_ROOT);

        $ids = $this->ids($this->structured($this->callTool($this->tokenFor($orderStaff), 'recent_changes', ['since' => now()->subDay()->toIso8601String()])));

        $this->assertContains($order, $ids);
        $this->assertNotContains($stock, $ids);
    }

    public function test_a_user_who_may_open_no_event_screen_does_not_see_the_tool(): void
    {
        $nobody = $this->mcpUser();

        $this->assertNotContains('recent_changes', $this->toolNames($this->tokenFor($nobody)));
    }

    public function test_a_store_scoped_owner_reads_only_events_of_their_store(): void
    {
        $store = 'ZZ-STORE-EVT';
        config(['gp247-config.mcp.store_resolver' => static fn () => $store]);
        $token = $this->tokenFor($this->administrator());
        $mine = $this->event('order.created', $store);
        $foreign = $this->event('order.created', GP247_STORE_ID_ROOT);

        $ids = $this->ids($this->structured($this->callTool($token, 'recent_changes', [])));

        $this->assertContains($mine, $ids);
        $this->assertNotContains($foreign, $ids);
    }

    public function test_paging_with_after_and_limit(): void
    {
        $token = $this->tokenFor($this->administrator());
        $a = $this->event('order.created', GP247_STORE_ID_ROOT, now()->subMinutes(30));
        $b = $this->event('order.created', GP247_STORE_ID_ROOT, now()->subMinutes(20));
        $since = now()->subMinutes(40)->toIso8601String();

        $page = $this->structured($this->callTool($token, 'recent_changes', ['since' => $since, 'types' => ['order.created'], 'after' => $a - 1, 'limit' => 1]));

        $this->assertSame([$a], $this->ids($page));
        $this->assertTrue($page['has_more']);
        $this->assertSame($a, $page['next_after']);
        $next = $this->structured($this->callTool($token, 'recent_changes', ['since' => $since, 'types' => ['order.created'], 'after' => $a, 'limit' => 1]));
        $this->assertSame([$b], $this->ids($next));
    }

    public function test_a_turned_off_event_log_is_explained_not_an_error(): void
    {
        $this->mcpApiSwitch('api_event_log', false);
        $token = $this->tokenFor($this->administrator());

        $data = $this->structured($this->callTool($token, 'recent_changes', []));

        $this->assertFalse($data['enabled']);
        $this->assertNotSame('', $data['hint']);
    }

    public function test_the_tool_is_read_only(): void
    {
        $tool = collect($this->mcp($this->tokenFor($this->administrator()), 'tools/list')->json('result.tools'))->firstWhere('name', 'recent_changes');

        $this->assertTrue($tool['annotations']['readOnlyHint'] ?? false);
    }
}
