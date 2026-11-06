<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| El SPA
|--------------------------------------------------------------------------
|
| En produccion el build de React queda dentro de public/ y se sirve desde
| el mismo dominio que la API. Las rutas del sistema —/empresas/4138,
| /indice/...— las resuelve React Router en el navegador, asi que todo lo
| que no matchee una ruta de Laravel tiene que devolver el index.html para
| que el SPA arranque y se encargue.
|
| En local ese archivo no existe: el SPA lo sirve Vite en el 5180 y esto
| queda mostrando la pantalla de siempre de Laravel.
|
*/

$spa = function () {
    $index = public_path('index.html');

    if (! file_exists($index)) {
        return view('welcome');
    }

    /*
      La pantalla se pide cada vez, sin guardarla en el navegador.

      Un archivo servido con response()->file() sale "Cache-Control: public" sin vencimiento, y el
      navegador lo reusa durante horas sin preguntar: despues de instalar una version, quien abria
      el sistema seguia viendo la pantalla vieja (06-11-2026: el boton de borradores seguia llevando
      al estado viejo con la version nueva ya instalada). Los archivos de assets/ llevan el hash en el
      nombre, asi que esos si se guardan.
    */
    $respuesta = response()->file($index);
    $respuesta->headers->set('Cache-Control', 'no-cache, must-revalidate');

    return $respuesta;
};

Route::get('/', $spa);
Route::fallback($spa);
