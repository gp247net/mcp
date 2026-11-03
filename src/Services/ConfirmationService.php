<?php

namespace GP247\Mcp\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Server-side two-step confirmation for write tools: a preview call issues a short-lived,
 * single-use code bound to (token, tool, arguments); only a call presenting that code applies.
 * Clients' own confirmation prompts are hints the server cannot rely on.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-order-status-write
 * @aidlc-adr ADR-plugin-mcp-server-token-auth
 */
class ConfirmationService
{
    public const TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'gp247-mcp:confirm:';

    /**
     * Issue a code for the given change.
     *
     * @param array<string, mixed> $arguments Arguments that define the change (without confirm_code).
     * @return string 16 hex characters.
     */
    public function issue(int|string $tokenId, string $tool, array $arguments): string
    {
        $code = bin2hex(random_bytes(8));
        Cache::put($this->cacheKey($code), $this->fingerprint($tokenId, $tool, $arguments), self::TTL_SECONDS);

        return $code;
    }

    /**
     * Consume a code: true only when it exists, has not expired and matches this exact change.
     * The code is removed in every case, so a mismatching attempt also burns it.
     */
    public function consume(string $code, int|string $tokenId, string $tool, array $arguments): bool
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $code)) {
            return false;
        }
        $key = $this->cacheKey($code);
        // WHY add(): atomic on database/redis stores, so of two concurrent confirm calls only one
        // may proceed; the order row lock in the write path is the second line of defence.
        if (!Cache::add($key . ':used', 1, self::TTL_SECONDS)) {
            return false;
        }
        $stored = Cache::pull($key);

        return is_string($stored) && hash_equals($stored, $this->fingerprint($tokenId, $tool, $arguments));
    }

    /**
     * Cache key of a code (hashed).
     */
    private function cacheKey(string $code): string
    {
        // WHY hash: the cache store (often the DB) never holds the usable code itself.
        return self::CACHE_PREFIX . hash('sha256', $code);
    }

    /**
     * Hash of the approved change, independent of argument order.
     *
     * @param array<string, mixed> $arguments
     */
    private function fingerprint(int|string $tokenId, string $tool, array $arguments): string
    {
        ksort($arguments);

        return hash('sha256', json_encode([(string) $tokenId, $tool, $arguments], JSON_UNESCAPED_UNICODE));
    }
}
