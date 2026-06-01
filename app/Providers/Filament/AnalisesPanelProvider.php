<?php

namespace App\Providers\Filament;

use App\Filament\Analises\Pages\ContractAnalysis\ContractAnalysis as ContractAnalysisPage;
use App\Filament\Analises\Pages\UserProfile;
use App\Http\Middleware\FilamentAuthenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AnalisesPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('analises')
            ->path('analises')
            ->brandName('OLHADINHA - Análises Jurídicas')
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('3rem')
            ->login()
            ->passwordReset()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Analises/Resources'), for: 'App\Filament\Analises\Resources')
            ->discoverPages(in: app_path('Filament/Analises/Pages'), for: 'App\Filament\Analises\Pages')
            ->pages([
                ContractAnalysisPage::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Analises/Widgets'), for: 'App\Filament\Analises\Widgets')
            ->widgets([])
            ->userMenuItems([
                'meu-perfil' => MenuItem::make()
                    ->label('Meu Perfil')
                    ->url(fn (): string => UserProfile::getUrl())
                    ->icon('heroicon-o-user-circle')
                    ->sort(1),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                FilamentAuthenticate::class,
            ])
            ->sidebarCollapsibleOnDesktop();
    }
}
