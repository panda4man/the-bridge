<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\OpenApi\OpenApiSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Ported from reference/src/routes/api.ts:29-52 (GET /openapi.json, GET
 * /docs). Both public — no api.token middleware.
 */
class DocsController extends Controller
{
    public function schema(): JsonResponse
    {
        return response()->json(OpenApiSchema::toArray());
    }

    /**
     * Swagger UI shell that loads swagger-ui-dist@5 from unpkg (kept as a
     * CDN reference, not vendored — see reference/src/routes/api.ts:33-51).
     */
    public function ui(): Response
    {
        return response()
            ->view('api.docs')
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
