<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  Lo que la empresa ajusta desde Configuracion y no tiene tabla propia.

  Empezo con el envio de cotizaciones (29-10-2026): el texto que acompaña la
  hoja por WhatsApp y por correo era fijo en el sistema, y la casilla desde
  la que sale el correo la carga un administrador desde el navegador. La
  clave de esa casilla se guarda cifrada.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes', function (Blueprint $tabla) {
            $tabla->string('clave', 60)->primary();
            $tabla->text('valor')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes');
    }
};
