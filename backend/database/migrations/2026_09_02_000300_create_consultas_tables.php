<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las consultas: el historial de cada empresa.
 *
 * Se llaman "consultas" porque es la palabra del sistema que ya usan
 * (Consultas por fecha, Busq. Consultas p/Cond.). El campo `tipo` las separa
 * en las tres secciones de la ficha: Cotización, Pedido y Observación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->enum('tipo', ['Cotizacion', 'Pedido', 'Observacion']);
            $table->date('fecha');

            // La validez arranca en 7 días y el sistema calcula el vencimiento.
            $table->unsignedSmallInteger('validez_dias')->default(7);
            $table->date('vence_el')->nullable();

            // La solicitud, tal cual llegó. Sirve para releerla y para comparar
            // contra lo que se terminó cotizando, línea por línea.
            $table->enum('solicitud_via', ['Mail', 'WhatsApp', 'Telefono', 'En persona'])->nullable();
            $table->date('solicitud_fecha')->nullable();
            $table->text('solicitud_texto')->nullable();

            $table->foreignId('contacto_id')->nullable()->constrained('contactos');
            $table->foreignId('razon_social_id')->nullable()->constrained('razones_sociales');
            $table->foreignId('usuario_id')->constrained('users');       // "Quien lo hizo"
            $table->foreignId('moneda_id')->nullable()->constrained('monedas');
            $table->decimal('tipo_cambio', 14, 4)->nullable();

            $table->boolean('ajuste_dif_cambio')->default(false);
            $table->string('ajuste_dif_cambio_detalle', 120)->nullable();

            $table->string('nro_factura', 20)->nullable();
            $table->string('id_sistema', 20)->nullable();
            $table->string('condicion_pago', 80)->nullable();
            $table->string('lista_precios', 40)->nullable();

            $table->text('nota')->nullable();     // la NOTA: nunca se imprime
            $table->text('texto')->nullable();    // el contenido cuando el tipo es Observacion

            $table->enum('estado', [
                'Borrador', 'Sin cotizar', 'Confirmada', 'Vendida', 'Sin vender', 'Vencida',
            ])->default('Confirmada');

            // De qué cotización salió, cuando se copió a otra empresa.
            $table->foreignId('copiada_de_id')->nullable()->constrained('consultas');

            $table->timestamps();

            $table->index(['empresa_id', 'tipo', 'fecha']);
            $table->index('fecha');
            $table->index('estado');
        });

        Schema::create('consulta_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);

            // --- lo que pidió el cliente ---
            $table->decimal('cantidad_pedida', 14, 2)->nullable();
            $table->foreignId('unidad_pedida_id')->nullable()->constrained('unidades');
            $table->string('pedido_material', 120)->nullable();
            $table->string('pedido_forma', 60)->nullable();
            $table->string('pedido_dimensiones', 120)->nullable();

            // --- el check ---
            // Viene marcado por defecto: si se cotiza tal cual, no hay nada que completar.
            $table->boolean('igual_a_lo_pedido')->default(true);
            $table->string('motivo_cambio', 120)->nullable();

            // --- lo que se cotiza ---
            $table->foreignId('material_id')->nullable()->constrained('materiales');
            $table->foreignId('forma_id')->nullable()->constrained('formas');
            $table->string('dimensiones', 120)->nullable();
            $table->decimal('diametro_mm', 12, 2)->nullable();
            $table->decimal('largo_mm', 12, 2)->nullable();
            $table->decimal('espesor_mm', 12, 2)->nullable();

            // El texto completo tal como sale impreso. No se pierde.
            $table->text('descripcion');

            // --- cantidades y precios ---
            $table->decimal('cantidad', 14, 2)->nullable();
            $table->foreignId('unidad_venta_id')->nullable()->constrained('unidades');
            $table->foreignId('unidad_factura_id')->nullable()->constrained('unidades');
            // Cuántas unidades de facturación entran en una de venta: los kilos que
            // pesa cada metro. Se propone calculado y se puede corregir a mano.
            $table->decimal('factor_conversion', 14, 4)->nullable();
            $table->decimal('cantidad_facturar', 14, 2)->nullable();
            $table->decimal('precio_unitario', 14, 2)->nullable();
            $table->decimal('precio_por_kilo', 14, 2)->nullable();
            $table->decimal('importe', 14, 2)->nullable();

            $table->boolean('aprox')->default(false);
            $table->boolean('idem')->default(false);

            // Solo en los pedidos (venta de stock).
            $table->boolean('desde_stock')->default(false);
            $table->string('deposito', 40)->nullable();
            $table->string('colada', 40)->nullable();

            // Se quitó del borrador copiado; se puede volver a poner.
            $table->boolean('quitada')->default(false);

            $table->timestamps();
            $table->index(['material_id', 'forma_id']);   // para el aviso de "ya le cotizamos esto"
        });

        // Las que sí salen impresas en la hoja del cliente.
        Schema::create('consulta_condiciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->string('texto', 200);
            $table->enum('origen', ['Manual', 'De la ficha', 'Automatica'])->default('Manual');
            $table->timestamps();
        });

        // Observación 1, 2, 3… el hilo interno de cada cotización.
        Schema::create('observaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->dateTime('fecha');
            $table->foreignId('usuario_id')->constrained('users');
            $table->text('texto');
            $table->timestamps();
        });

        // Cada vez que se imprime o se manda: con qué datos salió.
        // Elegir otro contacto acá no toca la ficha.
        Schema::create('impresiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas')->cascadeOnDelete();
            $table->dateTime('fecha');
            $table->foreignId('usuario_id')->constrained('users');
            $table->string('nombre_en_pdf', 150);
            $table->foreignId('contacto_id')->nullable()->constrained('contactos');
            $table->string('telefono', 60)->nullable();
            $table->string('mail', 120)->nullable();
            $table->enum('via', ['Impresora', 'PDF', 'Correo', 'WhatsApp']);
            $table->boolean('vencida_al_mandar')->default(false);
            $table->boolean('incluye_importes')->default(true);
            $table->boolean('incluye_nota')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impresiones');
        Schema::dropIfExists('observaciones');
        Schema::dropIfExists('consulta_condiciones');
        Schema::dropIfExists('consulta_lineas');
        Schema::dropIfExists('consultas');
    }
};
