<?php

namespace GP247\Mcp\Http\Controllers;

use GP247\Mcp\Support\EdgeResponse;
use GP247\Mcp\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Diagnostic probe used by the "Check" button: tells whether the Authorization header reaches PHP
 * on this web server. Reveals nothing about the shop; hidden while the endpoint is disabled.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-connect-guide
 */
class ProbeController
{
    /**
     * Answer the probe.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (!Settings::endpointEnabled() || !EdgeResponse::apiMasterSwitchOn()) {
            abort(404);
        }
        $header = (string) $request->headers->get('Authorization', '');

        return response()->json([
            'ok' => true,
            'authorization_header' => str_starts_with($header, 'Bearer '),
            'https' => $request->isSecure(),
        ]);
    }
}
