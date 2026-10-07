<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alternativas que son lineas completas, y las caracteristicas del producto.
 *
 * Alternativas. Eran "opciones" que solo podian cambiar la cantidad, el
 * precio, el plazo o el material: una medida cercana ("piden 50,8, ofrezco
 * Ø50") se cargaba como una linea 2 aparte, y una alternativa cobrada por
 * metro se calculaba por kilo. Ahora una alternativa es una linea entera que
 * cuelga de otra (alternativa_de_id): su material, forma, medidas, unidades,
 * factor, precio, transporte y plazo. En la hoja va como 1.1, 1.2 debajo de
 * su linea, y no suma al total.
 *
 * Caracteristicas. "Con o sin costura, laminada o estirada, la norma": lo que
 * completa al material, la forma y la medida. Una para lo que pidio el
 * cliente y otra para lo que se le ofrece, que se imprime. La lista de
 * sugerencias crece con lo que se escribe, como los motivos de cambio.
 */
return new class extends Migration
{
    /** Las de proceso y las normas mas comunes de lo que vende CORDES. */
    private const CARACTERISTICAS = [
        'SIN COSTURA', 'CON COSTURA', 'LAMINADA', 'ESTIRADA EN FRIO', 'FORJADA', 'RECTIFICADA', 'RECOCIDA',
        // Titanio: barras, tubos, caños sin y con costura, chapa, implantes.
        'ASTM B348', 'ASTM B338', 'ASTM B861', 'ASTM B862', 'ASTM B265', 'ASTM F67', 'ASTM F136',
        // Inoxidable: barras, caño, tubo, chapa.
        'ASTM A276', 'ASTM A479', 'ASTM A312', 'ASTM A269', 'ASTM A213', 'ASTM A240',
        // Niquel: Monel 400, Inconel 600 y 625, Hastelloy C-276.
        'ASTM B164', 'ASTM B166', 'ASTM B446', 'ASTM B574',
    ];

    public function up(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $tabla) {
            $tabla->foreignId('alternativa_de_id')->nullable()->after('consulta_id')
                ->constrained('consulta_lineas')->cascadeOnDelete();
            // Maritimo o Aereo, y en cuantos dias se entrega.
            $tabla->string('transporte', 20)->nullable();
            $tabla->unsignedSmallInteger('plazo_dias')->nullable();
            $tabla->string('caracteristicas', 120)->nullable();
            $tabla->string('pedido_caracteristicas', 120)->nullable();
        });

        Schema::create('caracteristicas', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 120)->unique();
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });

        foreach (self::CARACTERISTICAS as $i => $nombre) {
            DB::table('caracteristicas')->insert([
                'nombre' => $nombre, 'orden' => $i + 1, 'activo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->opcionesALineas();
    }

    /**
     * Las opciones que ya estaban pasan a ser lineas alternativas.
     *
     * La base se vuelca en la linea misma (su transporte, plazo, cantidad y
     * precio); cada una de las otras es una copia de la linea con lo suyo.
     * Las opciones quedan en su tabla, sin uso: no se borra nada.
     */
    private function opcionesALineas(): void
    {
        $porLinea = DB::table('consulta_linea_opciones')->orderBy('orden')->get()->groupBy('consulta_linea_id');

        foreach ($porLinea as $lineaId => $opciones) {
            $linea = DB::table('consulta_lineas')->where('id', $lineaId)->first();

            if (! $linea) {
                continue;
            }

            $base = $opciones->firstWhere('es_base', true) ?? $opciones->first();

            DB::table('consulta_lineas')->where('id', $linea->id)->update($this->conLaOpcion((array) $linea, $base));

            foreach ($opciones->reject(fn ($o) => $o->id === $base->id) as $opcion) {
                $copia = $this->conLaOpcion((array) $linea, $opcion);
                unset($copia['id']);
                $copia['alternativa_de_id'] = $linea->id;
                $copia['created_at'] = $copia['updated_at'] = now();

                DB::table('consulta_lineas')->insert($copia);
            }
        }
    }

    /** La linea con lo que cambia la opcion: transporte, plazo, cantidad, precio. */
    private function conLaOpcion(array $linea, object $opcion): array
    {
        $etiqueta = trim((string) $opcion->etiqueta);
        $transporte = match (mb_strtolower($etiqueta)) {
            'maritimo', 'marítimo' => 'Marítimo',
            'aereo', 'aéreo' => 'Aéreo',
            default => null,
        };

        $cantidad = $opcion->cantidad ?? $linea['cantidad'];
        $precio = $opcion->precio_unitario ?? $linea['precio_unitario'];
        $cambia = $linea['unidad_factura_id'] && $linea['unidad_factura_id'] !== $linea['unidad_venta_id']
            && $linea['factor_conversion'];

        return array_merge($linea, array_filter([
            'transporte' => $transporte,
            'plazo_dias' => $opcion->plazo_dias,
            'cantidad' => $cantidad,
            'cantidad_facturar' => $opcion->cantidad !== null
                ? round((float) $cantidad * ($cambia ? (float) $linea['factor_conversion'] : 1.0), 2)
                : null,
            'precio_unitario' => $precio,
            'precio_por_kilo' => $opcion->precio_por_kilo,
            'material_id' => $opcion->material_id,
            'descripcion' => $opcion->descripcion,
            // Lo que no es un transporte ("Medida alternativa dia. 35mm") queda
            // escrito en la nota, que se imprime con la linea.
            'nota' => $transporte === null && $etiqueta !== ''
                ? trim($etiqueta.'. '.($opcion->nota ?? $linea['nota'] ?? ''), ' .')
                : ($opcion->nota ?? null),
            'importe' => $cantidad !== null && $precio !== null ? round((float) $cantidad * (float) $precio, 2) : null,
        ], fn ($v) => $v !== null));
    }

    public function down(): void
    {
        DB::table('consulta_lineas')->whereNotNull('alternativa_de_id')->delete();

        Schema::table('consulta_lineas', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('alternativa_de_id');
            $tabla->dropColumn(['transporte', 'plazo_dias', 'caracteristicas', 'pedido_caracteristicas']);
        });

        Schema::dropIfExists('caracteristicas');
    }
};
