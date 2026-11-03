<?php

namespace GP247\Mcp\Services;

use GP247\Mcp\Support\SafeReport;
use GP247\Core\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Store scope (L4) of an MCP request — ADR-plugin-mcp-server-store-resolution.
 *
 * MCP requests carry no session, so the admin store resolver (session based) cannot be used:
 * a partitioned site must register gp247-config.mcp.store_resolver, or the admin REST API fence
 * gp247-config.api.admin_fence (binds a store-admin to the store of the request domain), otherwise
 * only administrators (ROOT) may connect. Any doubt resolves to "denied".
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 * @aidlc-adr ADR-plugin-mcp-server-store-resolution
 */
class StoreContext
{
    /**
     * Store id the user works on, or null when MCP must be refused.
     *
     * @param Request|null $request Current request, handed to the admin API fence (it reads the domain).
     */
    public function resolve(AdminUser $user, ?Request $request = null): ?string
    {
        $mcpResolver = config('gp247-config.mcp.store_resolver');
        if ($mcpResolver !== null && $mcpResolver !== '') {
            if (!is_callable($mcpResolver)) {
                return null;
            }
            try {
                $storeId = $mcpResolver($user);
            } catch (\Throwable $e) {
                SafeReport::throwable($e);

                return null;
            }

            return ($storeId === null || $storeId === '') ? null : (string) $storeId;
        }

        $adminResolver = config('gp247-config.admin.store_resolver');
        $adminResolver = !empty($adminResolver) && is_callable($adminResolver) ? $adminResolver : null;
        $fence = config('gp247-config.api.admin_fence');
        if (is_callable($fence)) {
            return $this->resolveThroughFence($user, $request ?? request(), $fence, $adminResolver);
        }
        if ($adminResolver !== null) {
            return $user->isAdministrator() ? $this->root() : null;
        }

        return $this->root();
    }

    /**
     * Limit a query to the store scope (ROOT on a partitioned site sees every store, like the admin lists).
     */
    public function constrain(Builder $query, string $storeId, string $column = 'store_id'): Builder
    {
        if ($this->seesAllStores($storeId)) {
            return $query;
        }

        return $query->where($query->getModel()->qualifyColumn($column), $storeId);
    }

    /**
     * Whether a record of the given store is inside the scope.
     */
    public function contains(string $storeId, $recordStoreId): bool
    {
        return $this->seesAllStores($storeId) || (string) $recordStoreId === $storeId;
    }

    /**
     * Same order and fail-closed rules as the core ApiAdminAuthorize middleware: the fence first
     * (false or a throw denies) — also on a site without a store resolver, like the core — then the admin store resolver (a throw denies; an empty answer
     * is ROOT for an administrator only). The fence gets no admin route: one MCP request spans
     * several screens, so a fence that needs a specific screen must treat null as a refusal.
     *
     * @aidlc-story US-plugin-mcp-server-rbac-tools
     */
    private function resolveThroughFence(AdminUser $user, Request $request, callable $fence, ?callable $adminResolver): ?string
    {
        try {
            if (call_user_func($fence, $user, $request, null) !== true) {
                return null;
            }
            // WHY ROOT without a resolver: a site without store partitioning has one store.
            $storeId = $adminResolver !== null ? call_user_func($adminResolver) : $this->root();
        } catch (\Throwable $e) {
            SafeReport::throwable($e);

            return null;
        }
        if ($storeId === null || $storeId === '' || $storeId === false) {
            // WHY: an empty answer must not widen a confined admin to every store.
            return $user->isAdministrator() ? $this->root() : null;
        }

        return (string) $storeId;
    }

    private function seesAllStores(string $storeId): bool
    {
        if ($storeId !== $this->root()) {
            return false;
        }

        return (function_exists('gp247_store_check_multi_store_installed') && gp247_store_check_multi_store_installed())
            || (function_exists('gp247_store_check_multi_partner_installed') && gp247_store_check_multi_partner_installed());
    }

    private function root(): string
    {
        return (string) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
    }
}
