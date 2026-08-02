<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->brandName('The Bridge')
            ->brandLogo(asset('the-bridge-logo.png'))
            // The reference shipped a 0-byte favicon.ico (one of the defects
            // the plan lists as "fix explicitly during the port"); Phase 8
            // rebuilt it at 16/32/48px from the same logo. Declared here so the
            // panel emits the link tag rather than relying on the browser's
            // implicit /favicon.ico request.
            ->favicon(asset('favicon.ico'))
            ->darkMode()
            ->colors([
                'primary' => Color::hex('#FF9900'),
                'danger' => Color::hex('#CC0000'),
                'info' => Color::hex('#3399FF'),
                'warning' => Color::hex('#FFCC00'),
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // No Dashboard, and no default dashboard widgets. The panel's
            // root page is the APPS LIST — see AppResource's $slug docblock
            // for why, and note that registering Dashboard here would put a
            // second page on `/` and shadow it.
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
