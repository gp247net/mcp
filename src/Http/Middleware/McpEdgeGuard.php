<?php

namespace GP247\Mcp\Http\Middleware;

use GP247\Mcp\Services\AuditLog;
use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Services\McpToken;
use GP247\Mcp\Services\StoreContext;
use GP247\Mcp\Services\ToolAuthorizer;
use GP247\Mcp\Support\EdgeResponse;
use GP247\Mcp\Tools\ToolCatalog;
use Closure;
use GP247\Core\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post-authentication guard of the MCP endpoint: token must be an MCP token, the account active
 * and allowed to use MCP (L1), the store scope resolvable (L4). Then the admin guard is given the
 * token owner so RBAC, SandboxDemo and audit see the same actor as on the admin screens.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 * @aidlc-story US-plugin-mcp-server-audit-log
 * @aidlc-adr ADR-plugin-mcp-server-endpoint-placement
 * @aidlc-adr ADR-plugin-mcp-server-store-resolution
 */
class McpEdgeGuard
{
    public function __construct(
        private readonly ToolAuthorizer $authorizer,
        private readonly StoreContext $stores,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Handle the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // WHY: never let a context from an earlier request in the same process (Octane, tests) leak in.
        app()->forgetInstance(McpContext::class);
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (!$user instanceof AdminUser || !$token instanceof PersonalAccessToken) {
            return $this->unauthenticated();
        }
        if ((int) $user->status !== 1) {
            // WHY the same body as a bad token: a locked account must not be distinguishable.
            return $this->unauthenticated();
        }
        if (!McpToken::isMcp($token) || !$this->authorizer->mayUseMcp($user)) {
            return EdgeResponse::refuse(403, 'insufficient_scope', 'This token cannot use MCP');
        }
        // WHY before resolving the store: store resolvers and fences read the actor through the
        // admin guard (AdminUserContract), as the core admin API does (ApiAdminAuthorize).
        Auth::guard('admin')->setUser($user);
        $storeId = $this->stores->resolve($user, $request);
        if ($storeId === null) {
            return EdgeResponse::refuse(403, 'store_scope_denied', 'This account cannot use MCP on this store');
        }

        app()->instance(McpContext::class, new McpContext($user, $token, $storeId));
        $this->auditRefusedCall($request, $user, $token);

        return $next($request);
    }

    /**
     * laravel/mcp answers "Tool not found" for tools hidden by shouldRegister() without reaching
     * the tool; record those refusals here so every tools/call leaves a trace.
     */
    private function auditRefusedCall(Request $request, AdminUser $user, PersonalAccessToken $token): void
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body) || ($body['method'] ?? null) !== 'tools/call') {
            return;
        }
        $name = $body['params']['name'] ?? null;
        $name = is_string($name) ? $name : '';
        $definition = ToolCatalog::find($name);
        if ($definition !== null && $this->authorizer->allows($user, $token, $definition)) {
            return;
        }
        $arguments = $body['params']['arguments'] ?? [];
        $this->audit->toolCall(
            $user,
            $token,
            $definition !== null ? $name : 'unknown',
            is_array($arguments) ? $arguments : [],
            AuditLog::DENIED
        );
    }

    /**
     * 401 identical to the one of an unknown token (no account-state disclosure).
     */
    private function unauthenticated(): Response
    {
        return EdgeResponse::unauthenticated();
    }
}
