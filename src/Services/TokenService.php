<?php

namespace GP247\Mcp\Services;

use GP247\Core\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Issues, lists and revokes MCP tokens (Sanctum personal_access_tokens with mcp:* abilities).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-access-token
 * @aidlc-adr ADR-plugin-mcp-server-token-auth
 */
class TokenService
{
    /**
     * Create a token for the user. Input must already be validated by the caller.
     *
     * @param string   $level read|write
     * @param string[] $tools Optional tool allowlist.
     */
    public function issue(AdminUser $user, string $name, string $level, array $tools, int $days): NewAccessToken
    {
        $abilities = [McpToken::READ];
        if ($level === 'write') {
            $abilities[] = McpToken::WRITE;
        }
        foreach (array_values(array_unique($tools)) as $tool) {
            $abilities[] = McpToken::TOOL_PREFIX . $tool;
        }

        return $user->createToken($name, $abilities, now()->addDays($days));
    }

    /**
     * MCP tokens visible to the viewer: their own, or every one for an administrator.
     */
    public function visibleTo(AdminUser $viewer): Collection
    {
        return $this->scopeFor($viewer)->with('tokenable')->orderByDesc('id')->get();
    }

    /**
     * Revoke a token the viewer may see. Returns false when it is not theirs to revoke.
     */
    public function revoke(AdminUser $viewer, int $tokenId): bool
    {
        $token = $this->scopeFor($viewer)->whereKey($tokenId)->first();
        if ($token === null) {
            return false;
        }
        $token->delete();

        return true;
    }

    /**
     * Revoke every MCP token of the site (gp247:mcp-uninstall). Other API tokens are kept.
     *
     * @return int Number of tokens removed.
     */
    public function revokeAll(): int
    {
        return $this->mcpTokens()->delete();
    }

    private function scopeFor(AdminUser $viewer): Builder
    {
        $query = $this->mcpTokens();
        if (!$viewer->isAdministrator()) {
            $query->where('tokenable_type', $viewer->getMorphClass())->where('tokenable_id', $viewer->getKey());
        }

        return $query;
    }

    private function mcpTokens(): Builder
    {
        $model = Sanctum::personalAccessTokenModel();

        // WHY the quoted match: abilities is a JSON array; '"mcp:read"' cannot hit "mcp:readonly"-like values.
        return $model::query()->where('abilities', 'like', '%"' . McpToken::READ . '"%');
    }
}
