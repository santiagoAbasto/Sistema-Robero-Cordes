<?php

use App\Http\Controllers\AlternativaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\ConsultaEscrituraController;
use App\Http\Controllers\DireccionController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\EmpresaEscrituraController;
use App\Http\Controllers\FormaController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\ResumenController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Los numeros de la pantalla de inicio.
    Route::get('/resumen', [ResumenController::class, 'index']);

    // Lo que el sistema propone al armar alternativas: sale del historial.
    Route::get('/alternativas/sugerencias', [AlternativaController::class, 'sugerencias']);

    // Formas y sus cuentas de peso. Ojo con el orden de las rutas fijas.
    Route::get('/formas', [FormaController::class, 'index']);
    Route::post('/formas', [FormaController::class, 'store']);
    Route::post('/formas/probar', [FormaController::class, 'probar']);
    Route::post('/formas/orden', [FormaController::class, 'ordenar']);
    Route::put('/formas/{forma}', [FormaController::class, 'update']);
    // Si ya tiene cotizaciones no se borra: se desactiva. Lo decide el servidor.
    Route::delete('/formas/{forma}', [FormaController::class, 'destroy']);

    // Listas que el sistema propone (formas, materiales, monedas, unidades…).
    Route::get('/catalogos', [CatalogoController::class, 'index']);
    Route::get('/catalogos/materiales', [CatalogoController::class, 'materiales']);

    /* ------------------------------------------------------------- empresas */

    Route::get('/empresas', [EmpresaController::class, 'index']);
    Route::post('/empresas', [EmpresaEscrituraController::class, 'store']);

    // Ojo con el orden: {empresa} tiene que ir después de las rutas fijas,
    // si no "nueva" entraría acá y daría 404.
    Route::get('/empresas/{empresa}', [EmpresaController::class, 'show']);
    Route::put('/empresas/{empresa}', [EmpresaEscrituraController::class, 'update']);
    Route::post('/empresas/{empresa}/archivar', [EmpresaEscrituraController::class, 'archivar']);
    Route::post('/empresas/{empresa}/restaurar', [EmpresaEscrituraController::class, 'restaurar']);

    Route::get('/empresas/{empresa}/historial', [HistorialController::class, 'deEmpresa']);

    // Predictivos de direccion. La credencial queda en el servidor.
    Route::get('/direcciones/sugerencias', [DireccionController::class, 'sugerencias']);
    Route::get('/direcciones/detalle', [DireccionController::class, 'detalle']);

    // Enlaces: web, redes y mapa
    Route::post('/empresas/{empresa}/enlaces', [EmpresaEscrituraController::class, 'guardarEnlace']);
    Route::put('/empresas/{empresa}/enlaces/{enlace}', [EmpresaEscrituraController::class, 'guardarEnlace']);
    Route::delete('/enlaces/{enlace}', [EmpresaEscrituraController::class, 'borrarEnlace']);

    // Contactos
    Route::post('/empresas/{empresa}/contactos', [EmpresaEscrituraController::class, 'guardarContacto']);
    Route::put('/empresas/{empresa}/contactos/{contacto}', [EmpresaEscrituraController::class, 'guardarContacto']);
    Route::post('/contactos/{contacto}/archivar', [EmpresaEscrituraController::class, 'archivarContacto']);

    // Razones sociales
    Route::post('/empresas/{empresa}/razones-sociales', [EmpresaEscrituraController::class, 'guardarRazonSocial']);
    Route::put('/empresas/{empresa}/razones-sociales/{razon}', [EmpresaEscrituraController::class, 'guardarRazonSocial']);
    Route::post('/razones-sociales/{razon}/archivar', [EmpresaEscrituraController::class, 'archivarRazonSocial']);

    // Condiciones de trabajo (los campos propios de cada empresa)
    Route::post('/empresas/{empresa}/campos', [EmpresaEscrituraController::class, 'guardarCampo']);
    Route::put('/empresas/{empresa}/campos/{campo}', [EmpresaEscrituraController::class, 'guardarCampo']);
    Route::delete('/campos/{campo}', [EmpresaEscrituraController::class, 'borrarCampo']);

    // Consultas de una empresa
    Route::post('/empresas/{empresa}/consultas', [ConsultaEscrituraController::class, 'store']);

    /* ------------------------------------------------------------ consultas */

    // Precarga desde el texto que mandó el cliente.
    /* --------------------------------------- datos del sistema anterior */
    Route::get('/migracion', [\App\Http\Controllers\MigracionController::class, 'estado']);
    Route::post('/migracion/importar', [\App\Http\Controllers\MigracionController::class, 'importar']);
    Route::get('/migracion/sin-densidad', [\App\Http\Controllers\MigracionController::class, 'materialesSinDensidad']);
    // TEMPORAL: la pantalla de antes/ahora para que CORDES revise la carga.
    Route::get('/migracion/comparacion', [\App\Http\Controllers\MigracionController::class, 'comparacion']);

    Route::post('/consultas/interpretar', [ConsultaEscrituraController::class, 'interpretar']);

    /* --------------------------------------------------- seguimiento */
    // La campanita: lo que necesita atencion ahora.
    Route::get('/seguimiento/pendientes', [\App\Http\Controllers\SeguimientoController::class, 'pendientes']);
    // El balance del periodo, con su fecha de generacion.
    Route::get('/reportes/seguimiento', [\App\Http\Controllers\SeguimientoController::class, 'reporte']);

    Route::get('/consultas', [ConsultaController::class, 'index']);
    Route::get('/consultas/{consulta}', [ConsultaController::class, 'show']);
    Route::put('/consultas/{consulta}', [ConsultaEscrituraController::class, 'update']);
    Route::delete('/consultas/{consulta}', [ConsultaEscrituraController::class, 'destroy']);

    Route::get('/consultas/{consulta}/borradores', [ConsultaController::class, 'borradores']);
    Route::get('/consultas/{consulta}/relacionadas', [ConsultaController::class, 'relacionadas']);
    Route::post('/consultas/{consulta}/copiar', [ConsultaEscrituraController::class, 'copiar']);
    Route::post('/consultas/{consulta}/confirmar', [ConsultaEscrituraController::class, 'confirmar']);
    Route::post('/consultas/{consulta}/estado', [ConsultaEscrituraController::class, 'cambiarEstado']);
    Route::post('/consultas/{consulta}/observaciones', [ConsultaEscrituraController::class, 'agregarObservacion']);
    Route::delete('/observaciones/{observacion}', [ConsultaEscrituraController::class, 'borrarObservacion']);
    Route::post('/consultas/{consulta}/impresiones', [ConsultaEscrituraController::class, 'registrarImpresion']);

    // Quien ve que
    Route::get('/permisos', [PermisoController::class, 'index']);
    Route::put('/permisos/{usuario}', [PermisoController::class, 'actualizar']);
    Route::put('/empresas/{empresa}/visibilidad', [PermisoController::class, 'visibilidad']);

    // La hoja que recibe el cliente. Los datos de contacto se eligen al imprimir.
    Route::get('/consultas/{consulta}/pdf', [PdfController::class, 'cotizacion']);
});
