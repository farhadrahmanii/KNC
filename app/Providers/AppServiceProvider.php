<?php

namespace App\Providers;

use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Filament\FontProviders\LocalFontProvider;
use Guava\FilamentKnowledgeBase\Filament\Panels\KnowledgeBasePanel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        KnowledgeBasePanel::configureUsing(
            fn (KnowledgeBasePanel $panel) => $panel
                ->viteTheme('resources/css/filament/admin/theme.css') // your filament vite theme path here
                ->font('Bahij Helvetica Neue', url: '/css/bahij-font.css', provider: LocalFontProvider::class)
                ->brandLogo('/images/logo/omarzay-logo.png')
                ->darkModeBrandLogo('/images/logo/omarzay-logo-white.png')
                ->brandLogoHeight('2.25rem')
                ->favicon('/images/logo/omarzay-icon.png')
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['per', 'en', 'ps']); // also accepts a closure
        });
        Schema::defaultStringLength(191);

        // nginx does not tell PHP the request came over HTTPS, so asset links
        // would be http:// and the browser blocks them as mixed content.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
