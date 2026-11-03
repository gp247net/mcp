<?php

namespace GP247\Mcp\Http\Middleware;

use GP247\Mcp\Support\EdgeResponse;
use GP247\Mcp\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-authentication gate: endpoint switch and the master REST API switch (404, so a disabled site
 * does not reveal that MCP is present),
 * browser Origin allowlist (403, DNS-rebinding protection — laravel/mcp does not check Origin),
 * per-IP request budget before authentication (429) and request size cap (413).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-endpoint
 * @aidlc-adr ADR-plugin-mcp-server-endpoint-placement
 */
class McpEndpointGate
{
    /**
     * Handle the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Settings::endpointEnabled() || !EdgeResponse::apiMasterSwitchOn()) {
            abort(404);
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && !$this->originAllowed($origin)) {
            return EdgeResponse::refuse(403, 'forbidden_origin', 'Origin not allowed');
        }

        // WHY here and not a throttle middleware: Laravel's middleware priority always moves
        // `auth` before `throttle`, so only this gate can limit bad-token traffic per IP.
        $key = 'mcp-pre:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, Settings::int('throttle_per_minute', 1, 10000) * 5)) {
            return EdgeResponse::refuse(429, 'too_many_attempts', 'Too many requests', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        $maxBytes = Settings::int('max_request_kb', 1, 4096) * 1024;
        if (strlen((string) $request->getContent()) > $maxBytes) {
            return EdgeResponse::refuse(413, 'payload_too_large', 'Request body too large');
        }

        return $next($request);
    }

    /**
     * Whether a browser Origin is the site itself (APP_URL) or an origin the owner allowed.
     * The request Host header is deliberately not trusted: under DNS rebinding it equals the attacker's Origin.
     */
    private function originAllowed(string $origin): bool
    {
        $origin = strtolower(rtrim(trim($origin), '/'));
        $allowed = array_merge([$this->originOf((string) config('app.url'))], Settings::allowedOrigins());

        return in_array($origin, array_filter($allowed), true);
    }

    /**
     * scheme://host[:port] of a URL, lower case; '' when the URL is not absolute.
     */
    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
}
