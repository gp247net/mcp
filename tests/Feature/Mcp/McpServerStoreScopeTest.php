<?php

/**
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

/**
 * Store scope (L4) of MCP requests — ADR-plugin-mcp-server-store-resolution.
 */
class McpServerStoreScopeTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->enableEndpoint();
    }

    public function test_on_a_store_partitioned_site_without_an_mcp_resolver_only_administrators_may_connect(): void
    {
        config(['gp247-config.admin.store_resolver' => static fn () => GP247_STORE_ID_ROOT]);
        config(['gp247-config.mcp.store_resolver' => null]);

        $staff = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order']);

        $this->mcp($this->tokenFor($staff), 'tools/list')->assertStatus(403);
        $this->mcp($this->tokenFor($this->administrator()), 'tools/list')->assertOk();
    }

    public function test_a_registered_mcp_resolver_decides_the_store_of_each_user(): void
    {
        $staff = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order,GET::' . GP247_ADMIN_PREFIX . '/order/*']);
        $otherStore = 'ZZ-STORE-' . uniqid();
        config(['gp247-config.admin.store_resolver' => static fn () => GP247_STORE_ID_ROOT]);
        config(['gp247-config.mcp.store_resolver' => static fn ($user) => (string) $user->id === (string) $staff->id ? $otherStore : null]);

        $mine = $this->makeMcpOrder(['store_id' => $otherStore]);
        $foreign = $this->makeMcpOrder(['store_id' => GP247_STORE_ID_ROOT]);
        $token = $this->tokenFor($staff);

        $this->structured($this->callTool($token, 'get_order', ['order_id' => $mine->id]));
        $this->assertTrue((bool) $this->callTool($token, 'get_order', ['order_id' => $foreign->id])->json('result.isError'));

        $ids = array_column($this->structured($this->callTool($token, 'search_orders', ['per_page' => 50]))['items'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_a_resolver_that_refuses_a_user_blocks_the_endpoint_for_them(): void
    {
        config(['gp247-config.mcp.store_resolver' => static fn ($user) => null]);

        $this->mcp($this->tokenFor($this->administrator()), 'tools/list')->assertStatus(403);
    }

    public function test_with_an_admin_api_fence_a_store_admin_works_on_the_store_the_resolver_gives(): void
    {
        $staff = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order,GET::' . GP247_ADMIN_PREFIX . '/order/*']);
        $store = 'ZZ-STORE-FENCE-' . uniqid();
        $seen = [];
        config([
            'gp247-config.admin.store_resolver' => static fn () => $store,
            'gp247-config.api.admin_fence' => static function ($user, $request, $route) use (&$seen) {
                $seen = [$request instanceof \Illuminate\Http\Request, $route];

                return true;
            },
        ]);
        $mine = $this->makeMcpOrder(['store_id' => $store]);
        $foreign = $this->makeMcpOrder(['store_id' => GP247_STORE_ID_ROOT]);
        $token = $this->tokenFor($staff);

        $this->structured($this->callTool($token, 'get_order', ['order_id' => $mine->id]));
        $this->assertTrue($this->failed($this->callTool($token, 'get_order', ['order_id' => $foreign->id])));
        $this->assertSame([true, null], $seen);
    }

    public function test_a_fence_that_refuses_or_throws_closes_the_endpoint(): void
    {
        config(['gp247-config.admin.store_resolver' => static fn () => GP247_STORE_ID_ROOT]);
        $staff = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order']);

        config(['gp247-config.api.admin_fence' => static fn () => false]);
        $this->mcp($this->tokenFor($staff), 'tools/list')->assertStatus(403);

        config(['gp247-config.api.admin_fence' => static function () {
            throw new \RuntimeException('fence down');
        }]);
        $this->mcp($this->tokenFor($staff), 'tools/list')->assertStatus(403);
    }

    public function test_an_empty_resolver_answer_behind_the_fence_is_root_for_administrators_only(): void
    {
        config([
            'gp247-config.admin.store_resolver' => static fn () => null,
            'gp247-config.api.admin_fence' => static fn () => true,
        ]);
        $staff = $this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order']);

        $this->mcp($this->tokenFor($staff), 'tools/list')->assertStatus(403);
        $this->mcp($this->tokenFor($this->administrator()), 'tools/list')->assertOk();
    }

    public function test_a_resolver_that_reads_the_admin_contract_sees_the_token_owner(): void
    {
        // WHY: real resolvers (a multi-store plugin's store resolver) ask AdminUserContract, which reads the
        // admin guard — the guard must already hold the token owner when the resolver runs.
        config([
            'gp247-config.admin.store_resolver' => static fn () => app(\GP247\Core\AdminShell\Domain\AdminUserContract::class)->isAdministrator()
                ? GP247_STORE_ID_ROOT
                : 'ZZ-STAFF-STORE',
            'gp247-config.api.admin_fence' => static fn () => true,
        ]);

        $this->mcp($this->tokenFor($this->administrator()), 'tools/list')->assertOk();
        $this->mcp($this->tokenFor($this->mcpUser(['GET::' . GP247_ADMIN_PREFIX . '/order'])), 'tools/list')->assertOk();
    }

    public function test_a_fence_without_a_store_resolver_still_decides_like_the_admin_api(): void
    {
        config(['gp247-config.admin.store_resolver' => null, 'gp247-config.api.admin_fence' => static fn () => false]);

        $this->mcp($this->tokenFor($this->administrator()), 'tools/list')->assertStatus(403);
    }

    public function test_a_failing_resolver_fails_closed(): void
    {
        config(['gp247-config.mcp.store_resolver' => static function () {
            throw new \RuntimeException('resolver down');
        }]);

        $this->mcp($this->tokenFor($this->administrator()), 'tools/list')->assertStatus(403);
    }
}
