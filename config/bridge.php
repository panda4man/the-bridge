<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repos clone directory
    |--------------------------------------------------------------------------
    |
    | Base directory into which application repositories are cloned before
    | each deploy. Equivalent to REPOS_PATH in the original Express app.
    |
    */

    'repos_path' => env('BRIDGE_REPOS_PATH', '/repos'),

    /*
    |--------------------------------------------------------------------------
    | Git SSH key path
    |--------------------------------------------------------------------------
    |
    | Path to the private SSH key used when cloning/pulling private Git
    | repositories over SSH.
    |
    */

    'ssh_key_path' => env('BRIDGE_SSH_KEY_PATH', '/data/ssh/id_rsa'),

    /*
    |--------------------------------------------------------------------------
    | API bearer token
    |--------------------------------------------------------------------------
    |
    | Static bearer token required by the token-authenticated /api routes.
    |
    | This is the ENV LAYER ONLY. It is not the complete token resolution.
    | The original app resolves the token as: BRIDGE_API_TOKEN if non-empty,
    | otherwise the `api_token` row in the `settings` table — which is how
    | operators actually set it, via the Settings screen. See
    | reference/src/middleware/apiAuth.ts:5-10.
    |
    | A database lookup cannot live in a config file (config is cached and
    | resolved before the DB is meaningfully available), so Phase 5 must add
    | a resolver service that layers the settings fallback on top of this.
    |
    | Auth FAILS CLOSED. When neither source yields a token the API returns
    | 503 "API token not configured" to every caller. An empty value here
    | does NOT disable enforcement and must never be made to.
    |
    */

    'api_token' => env('BRIDGE_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Initial administrator
    |--------------------------------------------------------------------------
    |
    | Consumed once by DatabaseSeeder to create the first panel user, since
    | the panel has no public registration. Both email and password must be
    | set or seeding is skipped — there is deliberately no default password.
    |
    | The account is created only if absent; it is never updated, so changing
    | a password through the UI is not reverted on the next boot.
    |
    */

    'admin' => [
        'name' => env('BRIDGE_ADMIN_NAME', 'Administrator'),
        'email' => env('BRIDGE_ADMIN_EMAIL'),
        'password' => env('BRIDGE_ADMIN_PASSWORD'),
    ],

];
