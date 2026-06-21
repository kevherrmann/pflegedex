<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Hinter dem ngrok-Tunnel terminiert TLS extern; intern kommt http an Caddy/PHP.
        // In Produktion alle generierten URLs auf https zwingen -> kein Mixed-Content.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Passwort-Mindestanforderungen fuer ein Gesundheitsdaten-Backend.
        // (uncompromised() bewusst aus: keine externe API noetig, on-premise-tauglich.)
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
    }
}
