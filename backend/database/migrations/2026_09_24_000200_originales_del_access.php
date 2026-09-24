<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  Las filas del Access, tal cual vinieron.

  La pantalla "Antes y ahora" pone el dato original al lado del importado, y
  hasta ahora leia ese lado de los CSV sueltos en storage/app/migracion/. Esos
  archivos no viajan a ningun lado: no van a git —son datos de clientes— ni
  entran a la imagen del servidor. Puestos en el servidor, la mitad "antes" de
  la pantalla quedaba vacia y la comparacion no se podia hacer.

  Guardadas en la base, viajan con la base, que es el camino que ya recorren
  todos los demas datos.

  Es tan temporal como la pantalla: cuando CORDES revise y de el visto bueno,
  se van las dos.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migracion_originales', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('archivo', 60);

            /*
              El numero de fila dentro del archivo. No es decorativo: es lo
              unico que empareja cada cotizacion con su original, porque las
              cotizaciones del Access no traen clave propia.
            */
            $tabla->unsignedInteger('fila');

            $tabla->json('datos');

            $tabla->unique(['archivo', 'fila']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migracion_originales');
    }
};
