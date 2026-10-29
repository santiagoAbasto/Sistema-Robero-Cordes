<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
          Un pedido sin token que no venga del SPA no tiene a donde redirigir:
          la pantalla de login es del frontend, aca no existe una ruta 'login'.
          Sin esto, abrir /api/empresas a mano en el navegador tiraba un 500
          ("Route [login] not defined") en vez de mandar a la pantalla de entrada.
        */
        $middleware->redirectGuestsTo('/');

        /*
          La clave que pone un administrador en Usuarios llega como "clave", y
          Laravel le sacaba los espacios de los costados: se guardaba distinta
          de como se escribio y despues no entraba. La de entrar ("password")
          ya no se recortaba.
        */
        $middleware->trimStrings(except: ['clave']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
