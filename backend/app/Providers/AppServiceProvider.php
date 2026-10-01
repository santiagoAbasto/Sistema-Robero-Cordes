<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        /*
          Un usuario dado de baja no sigue adentro con la sesion que tenia
          abierta: se controla en cada pedido, no solo al entrar. Vale para
          la baja y para destildar "activo" al modificarlo.
        */
        Sanctum::authenticateAccessTokensUsing(
            fn ($token, bool $valido) => $valido && (bool) $token->tokenable?->activo,
        );
    }
}
