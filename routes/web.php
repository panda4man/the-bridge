<?php

// The Filament admin panel is mounted at the application root — see
// App\Providers\Filament\AdminPanelProvider. It serves "/", "/login",
// "/apps", "/apps/{record}" and friends.
//
// ---------------------------------------------------------------------------
// ROUTE PRECEDENCE — read before adding anything here.
// ---------------------------------------------------------------------------
// This file is registered AFTER the panel, not before. Filament registers its
// routes during provider boot; Illuminate's RouteServiceProvider defers loading
// this file via $this->booted(), which fires once every provider has booted.
//
// RouteCollection is keyed by method+URI, so a route declared here that
// collides with a panel URI does not lose — it REPLACES the panel's route and
// erases its name. Adding Route::get('/login') here deletes
// `filament.admin.auth.login` from the route table entirely, and the panel then
// throws RouteNotFoundException the next time it redirects an anonymous user.
// There is no boot-time or build-time warning.
//
// Never reuse a panel URI+method in this file.
//
// ---------------------------------------------------------------------------
// PHASE 5 — done. The webhook and the API are NOT here; still don't add them.
// ---------------------------------------------------------------------------
// Everything in this file is in the `web` middleware group, which includes
// Illuminate\Foundation\Http\Middleware\PreventRequestForgery (Laravel 13's
// rename of VerifyCsrfToken — the old $except array pattern does not apply).
//
// POST /apps/{id}/webhook is a public, unauthenticated, machine-to-machine
// call from GitHub carrying no CSRF token, so it cannot live in this file —
// registered here it would return 419 on every push. It now lives in
// routes/webhook.php, loaded from bootstrap/app.php's withRouting(then: ...)
// via a bare `require` (no Route::middleware('web')->group(...) wrapper),
// so it gets neither the `web` group nor the `/api` group.
//
// bootstrap/app.php's withRouting() now also passes `api:
// routes/api.php`, so /api/* is loaded under the standard `api` middleware
// group (throttle + SubstituteBindings, no CSRF) at the `/api` prefix —
// matching the shouldRenderJsonWhen($request->is('api/*')) declared in the
// same file. routes/api.php has no routes registered yet; that's Phase
// 5B/5C.
//
// This file (routes/web.php) still has no routes of its own — the
// precedence warning above still applies verbatim to anything added here in
// a later phase.
//
// Verified safe: POST /apps/{id}/webhook and GET /api/* do not collide with any
// panel URI — Filament's resource routes bind {record}, so apps/{record} and
// apps/{id}/webhook are distinct keys.
