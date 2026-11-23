<?php

namespace GP247\Mcp\Tests\Feature\Mcp\Support;

use GP247\Mcp\Support\Routes;
use GP247\Mcp\Support\Settings;
use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared setup for the MCP (gp247/mcp) Feature-Tests.
 *
 * Boots MCP without installing its data (no DDL — MCP owns no tables), creates
 * real admin users/permissions/tokens inside the DatabaseTransactions rollback, and
 * speaks JSON-RPC to the MCP endpoint the way a 2026-07-28 client does.
 */
trait McpServerFixtures
{
    protected const MCP_URL = '/api/core/mcp';

    /**
     * Register views, translations, Livewire namespace and routes of MCP (gp247/mcp).
     */
    protected function bootMcpServer(): void
    {
        // WHY: on a site where the McpServer plugin is still active the provider leaves the routes to
        // the plugin; tests always exercise the package, so namespaces and routes are ensured here.
        $path = base_path('vendor/gp247/mcp/src');
        $this->app['view']->addNamespace('gp247-mcp', $path . '/Views');
        $this->app['translator']->addNamespace('gp247-mcp', $path . '/Lang');
        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::addNamespace('gp247-mcp', classNamespace: 'GP247\\Mcp\\Livewire');
        }
        if (!\Illuminate\Support\Facades\Route::has('admin_mcp.tokens')) {
            Routes::register();
            $this->app['router']->getRoutes()->refreshNameLookups();
        }
        Routes::registerRateLimiters();
        Cache::flush();
        RateLimiter::clear('mcp');
        Settings::flush();

        // WHY: the dev DB may have a multi-store plugin active (store resolver + action fence). Tests start
        // from a single-store site; store-partitioned behaviour is set explicitly where tested.
        config(['gp247-config.admin.store_resolver' => null, 'gp247-config.admin.action_fence' => null, 'gp247-config.api.admin_fence' => null]);
        // WHY: the master REST API switch also closes MCP; the dev DB may have it off.
        $this->mcpApiSwitch('api_enabled', true);
    }

    /**
     * Set one REST API switch of the API management screen (GLOBAL admin_config row) inside the
     * test transaction, and drop the static config memo the switch is read through.
     */
    protected function mcpApiSwitch(string $key, bool $on): void
    {
        $table = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $where = ['key' => $key, 'store_id' => (string) GP247_STORE_ID_GLOBAL];
        if ($table->clone()->where($where)->exists()) {
            $table->clone()->where($where)->update(['value' => $on ? '1' : '0']);
        } else {
            $table->clone()->insert($where + ['group' => 'global', 'code' => 'api_config', 'value' => $on ? '1' : '0', 'sort' => 0, 'detail' => '']);
        }
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $empty);
        }
    }

    /**
     * Upsert one plugin setting row (GLOBAL scope).
     */
    protected function mcpSetting(string $key, $value): void
    {
        AdminConfig::query()->updateOrCreate(
            ['group' => Settings::GROUP, 'key' => Settings::PREFIX . $key, 'store_id' => (string) GP247_STORE_ID_GLOBAL],
            ['code' => Settings::CODE, 'value' => (string) $value, 'sort' => 0, 'detail' => '']
        );
        Settings::flush();
    }

    protected function enableEndpoint(): void
    {
        $this->mcpSetting('endpoint_enabled', '1');
    }

    /**
     * Create a real admin user (rolled back with the test transaction).
     *
     * @param string[] $httpUris  Direct permissions, e.g. ['GET::gp247_admin/order'].
     * @param string[] $roleSlugs Existing roles to attach, e.g. ['administrator'].
     */
    protected function makeAdmin(array $httpUris = [], array $roleSlugs = [], int $status = 1): AdminUser
    {
        $tag = 'zz-mcp-' . Str::lower(Str::random(8));
        $user = AdminUser::query()->create([
            'username' => $tag,
            'password' => Hash::make('secret-' . $tag),
            'name' => 'ZZ MCP ' . $tag,
            'email' => $tag . '@example.test',
            'status' => $status,
        ]);

        foreach ($httpUris as $uri) {
            $permission = AdminPermission::query()->create([
                'name' => $tag . '-' . Str::random(4),
                'slug' => $tag . '-' . Str::lower(Str::random(6)),
                'http_uri' => $uri,
            ]);
            $user->permissions()->attach($permission->id);
        }
        foreach ($roleSlugs as $slug) {
            $role = AdminRole::query()->where('slug', $slug)->first();
            if ($role) {
                $user->roles()->attach($role->id);
            }
        }

        return $user->fresh();
    }

    /** Admin that may use MCP (L1) plus the given screen permissions. */
    protected function mcpUser(array $httpUris = []): AdminUser
    {
        return $this->makeAdmin(array_merge(['ANY::' . GP247_ADMIN_PREFIX . '/mcp/tokens'], $httpUris));
    }

    protected function administrator(): AdminUser
    {
        return $this->makeAdmin([], ['administrator']);
    }

    /** Plain-text Sanctum token with the given abilities. */
    protected function tokenFor(AdminUser $user, array $abilities = ['mcp:read'], ?\DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken('zz-mcp-test', $abilities, $expiresAt ?? now()->addDays(30))->plainTextToken;
    }

    /**
     * Send one MCP 2026-07-28 JSON-RPC request with mirrored headers.
     */
    protected function mcp(?string $token, string $method, array $params = [], array $headers = []): TestResponse
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
            'io.modelcontextprotocol/clientInfo' => ['name' => 'zz-test', 'version' => '1'],
        ];
        $base = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];
        if ($token !== null) {
            $base['Authorization'] = 'Bearer ' . $token;
        }
        if ($method === 'tools/call') {
            $base['Mcp-Name'] = (string) ($params['name'] ?? '');
        }
        // WHY: guards cache the user across requests inside one test; production serves each
        // request in a fresh application, so every MCP call here starts unauthenticated too.
        $this->app['auth']->forgetGuards();

        return $this->postJson(self::MCP_URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], array_merge($base, $headers));
    }

    /** Call a tool and return the HTTP response. */
    protected function callTool(string $token, string $tool, array $arguments = []): TestResponse
    {
        return $this->mcp($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    }

    /** @return string[] Sorted tool names visible to the token. */
    protected function toolNames(string $token): array
    {
        $response = $this->mcp($token, 'tools/list');
        $response->assertOk();

        return collect($response->json('result.tools'))->pluck('name')->sort()->values()->all();
    }

    /** structuredContent of a successful tool call (fails the test otherwise). */
    protected function structured(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertNotTrue((bool) $response->json('result.isError'), (string) $response->getContent());

        return (array) $response->json('result.structuredContent');
    }

    /** True when the call ended as a tool error or a JSON-RPC error. */
    protected function failed(TestResponse $response): bool
    {
        return $response->json('error') !== null || (bool) $response->json('result.isError');
    }

    /** Minimal order row in the given store (defaults to ROOT). */
    protected function makeMcpOrder(array $overrides = []): ShopOrder
    {
        return ShopOrder::query()->create(array_merge([
            'customer_id' => 0,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'email' => 'zz-buyer-' . Str::lower(Str::random(6)) . '@example.test',
            'first_name' => 'ZZ',
            'last_name' => 'Buyer',
            'phone' => '0901234789',
            'address1' => '12 Secret Street',
            'status' => 1,
            'payment_status' => 0,
            'shipping_status' => 1,
            'subtotal' => 100,
            'tax' => 0,
            'shipping' => 0,
            'discount' => 0,
            'other_fee' => 0,
            'total' => 100,
            'received' => 0,
            'balance' => 100,
            'store_id' => GP247_STORE_ID_ROOT,
        ], $overrides));
    }

    protected function makeMcpProduct(array $overrides = []): ShopProduct
    {
        return ShopProduct::query()->create(array_merge([
            'sku' => 'ZZ-MCP-' . Str::upper(Str::random(8)),
            'alias' => 'zz-mcp-' . Str::lower(Str::random(8)),
            'price' => 30,
            'stock' => 100,
            'status' => 1,
            'kind' => 0,
            'store_id' => GP247_STORE_ID_ROOT,
        ], $overrides));
    }
}
