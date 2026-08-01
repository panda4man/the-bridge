<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Resolves the bearer token that guards the token-authenticated /api
 * routes. Ported from reference/src/middleware/apiAuth.ts:5-10
 * (resolveToken()).
 *
 * Two layers, in order:
 *
 * 1. config('bridge.api_token') — the BRIDGE_API_TOKEN env var, trimmed.
 *    Used if non-empty.
 * 2. Otherwise the `api_token` row in the `settings` table, trimmed — how
 *    operators actually set it, via the Settings screen.
 *
 * A database lookup cannot live in config/bridge.php itself: config is
 * cached and resolved before the database is meaningfully available. See
 * the block comment on 'api_token' in that file for the full rationale.
 *
 * Returns null when neither layer yields a non-empty value. Callers must
 * treat null as "auth cannot be enforced" (fail closed — a 503), never as
 * "auth is disabled."
 */
class ApiTokenResolver
{
    public function resolve(): ?string
    {
        $fromEnv = trim((string) config('bridge.api_token', ''));

        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $fromSettings = trim((string) (Setting::getValue('api_token') ?? ''));

        return $fromSettings !== '' ? $fromSettings : null;
    }
}
