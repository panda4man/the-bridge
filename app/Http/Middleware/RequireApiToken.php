<?php

namespace App\Http\Middleware;

use App\Services\ApiTokenResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from reference/src/middleware/apiAuth.ts:12-37 (requireApiToken).
 *
 * Response ladder, in order:
 *
 * 1. ApiTokenResolver::resolve() returns null → 503 "API token not
 *    configured". Fail-closed: an unconfigured token never means the API
 *    is public.
 * 2. No Authorization header, or it doesn't match `^Bearer\s+(.+)$` → 401
 *    "Missing or malformed Authorization header".
 * 3. Token mismatch (hash_equals) → 401 "Invalid token".
 * 4. Otherwise, pass through.
 *
 * Bodies are built explicitly with response()->json() rather than abort():
 * bootstrap/app.php declares shouldRenderJsonWhen(api/*), but Laravel's own
 * JSON error rendering uses a `message` key. bridge-mcp (the Python MCP
 * client) reads body["error"] and treats 503 as a distinct
 * BridgeApiConfigError, so the key and the status code are both
 * load-bearing.
 *
 * hash_equals() handles unequal-length comparisons safely on its own —
 * unlike Node's timingSafeEqual(), which throws on a length mismatch — so
 * there's no length pre-check to port here.
 */
class RequireApiToken
{
    public function __construct(private readonly ApiTokenResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $expected = $this->resolver->resolve();

        if ($expected === null) {
            return response()->json(['error' => 'API token not configured'], 503);
        }

        $header = $request->header('Authorization', '');

        if (! preg_match('/^Bearer\s+(.+)$/', $header, $matches)) {
            return response()->json(['error' => 'Missing or malformed Authorization header'], 401);
        }

        $provided = trim($matches[1]);

        if (! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        return $next($request);
    }
}
