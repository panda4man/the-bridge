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
// PHASE 5 — do not register the webhook or the API here.
// ---------------------------------------------------------------------------
// Everything in this file is in the `web` middleware group, which includes
// Illuminate\Foundation\Http\Middleware\PreventRequestForgery (Laravel 13's
// rename of VerifyCsrfToken — the old $except array pattern does not apply).
//
// POST /apps/{id}/webhook is a public, unauthenticated, machine-to-machine
// call from GitHub carrying no CSRF token. Registered here it returns 419 on
// every push and webhook deploys silently stop working. It needs a route file
// outside the `web` group, or an explicit withoutMiddleware.
//
// There is also no `api:` argument in bootstrap/app.php's withRouting() yet, so
// routes/api.php is not loaded at all — no /api prefix, no throttle:api, no
// stateless handling. Phase 5 must add it. Note bootstrap/app.php already
// declares shouldRenderJsonWhen($request->is('api/*')) for a namespace that
// cannot currently exist.
//
// Verified safe: POST /apps/{id}/webhook and GET /api/* do not collide with any
// panel URI — Filament's resource routes bind {record}, so apps/{record} and
// apps/{id}/webhook are distinct keys.
