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

    return file_exists($index)
        ? response()->file($index)
        : view('welcome');
};

Route::get('/', $spa);
Route::fallback($spa);
