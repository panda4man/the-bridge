<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RequireApiToken;
use App\Models\Setting;
use App\Services\ApiTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Ported from reference/src/middleware/apiAuth.ts:12-37 (requireApiToken).
 * Exercises the middleware directly — it isn't wired onto any route yet
 * (that's Phase 5B) — so this is the only coverage of its response ladder
 * until then.
 */
class RequireApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private function middleware(): RequireApiToken
    {
        return new RequireApiToken(new ApiTokenResolver);
    }

    private function passThroughNext(): \Closure
    {
        return fn (Request $request) => response()->json(['ok' => true]);
    }

    public function test_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);

        $request = Request::create('/api/apps', 'GET');
        $response = $this->middleware()->handle($request, $this->passThroughNext());

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['error' => 'API token not configured'], json_decode($response->getContent(), true));
    }

    public function test_returns_401_when_authorization_header_is_missing(): void
    {
        config(['bridge.api_token' => 'the-token']);

        $request = Request::create('/api/apps', 'GET');
        $response = $this->middleware()->handle($request, $this->passThroughNext());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Missing or malformed Authorization header'],
            json_decode($response->getContent(), true)
        );
    }

    public function test_returns_401_when_authorization_header_is_malformed(): void
    {
        config(['bridge.api_token' => 'the-token']);

        $request = Request::create('/api/apps', 'GET', server: ['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz']);
        $response = $this->middleware()->handle($request, $this->passThroughNext());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Missing or malformed Authorization header'],
            json_decode($response->getContent(), true)
        );
    }

    public function test_returns_401_when_token_does_not_match(): void
    {
        config(['bridge.api_token' => 'the-token']);

        $request = Request::create('/api/apps', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong-token']);
        $response = $this->middleware()->handle($request, $this->passThroughNext());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['error' => 'Invalid token'], json_decode($response->getContent(), true));
    }

    /**
     * The wrong token above is a different LENGTH to the real one, so it is
     * also rejected by a comparison that only checks length. This case uses
     * a wrong token of exactly the same length, which nothing but an actual
     * content comparison can reject.
     *
     * Verified by mutation: replacing hash_equals($expected, $provided) with
     * strlen($expected) === strlen($provided) leaves every other test in
     * this file green (QC mutation M2-length-only-token-compare) and is
     * killed only by this one. Without it, any 9-character bearer token
     * would authenticate against a 9-character secret.
     */
    public function test_returns_401_when_the_wrong_token_is_the_same_length_as_the_real_one(): void
    {
        config(['bridge.api_token' => 'the-token']);

        $request = Request::create('/api/apps', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer the-tokeX']);
        $response = $this->middleware()->handle($request, $this->passThroughNext());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['error' => 'Invalid token'], json_decode($response->getContent(), true));
    }

    public function test_passes_through_when_token_matches(): void
    {
        config(['bridge.api_token' => 'the-token']);

        $request = Request::create('/api/apps', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer the-token']);
        $response = $this->middleware()->handle($request, $this->passThroughNext());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], json_decode($response->getContent(), true));
    }

    public function test_passes_through_when_token_matches_the_settings_fallback(): void
    {
        config(['bridge.api_token' => '']);
        Setting::setValue('api_token', 'settings-token');

        $request = Request::create('/api/apps', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer settings-token']);
        $response = $this->middleware()->handle($request, $this->passThroughNext());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_trims_whitespace_around_the_provided_token(): void
    {
        config(['bridge.api_token' => 'the-token']);

        $request = Request::create('/api/apps', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer   the-token   ']);
        $response = $this->middleware()->handle($request, $this->passThroughNext());

        $this->assertSame(200, $response->getStatusCode());
    }
}
