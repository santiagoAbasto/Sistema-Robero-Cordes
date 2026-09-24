<?php

namespace App\Console\Commands;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use Illuminate\Console\Command;

/** Chequeo rápido de que los datos de ejemplo quedaron bien cargados. */
class RevisarDatos extends Command
{
    protected $signature = 'cordes:revisar';

    protected $description = 'Muestra un resumen de los datos del Indice Telefonico';

    public function handle(): int
    {
        $this->line('empresas: '.Empresa::count().' | consultas: '.Consulta::count().' | lineas: '.ConsultaLinea::count());
        $this->newLine();

        $e = Empresa::where('nombre', 'like', '%SILMAR%')
            ->with('relaciones', 'contactos.medios.tipoMedio', 'razonesSociales', 'campos')->first();

        $this->line('FICHA: '.$e->nombre);
        $this->line('  CUIT '.$e->cuit.'   ISIS '.$e->codigo_isis);
        $this->line('  relaciones: '.$e->relaciones->pluck('relacion')->join(', '));

        foreach ($e->contactos as $c) {
            $this->line('  - '.$c->nombre.' ('.$c->sector.')'.($c->principal ? ' [principal]' : ''));
            $this->line('      '.$c->medios->map(fn ($m) => $m->tipoMedio->nombre.' '.$m->valor)->join('  |  '));
        }

        $this->line('  razones sociales: '.$e->razonesSociales->count().'  campos: '.$e->campos->count());
        $this->newLine();

        $l = ConsultaLinea::whereNotNull('factor_conversion')->with('unidadVenta', 'unidadFactura', 'material')->first();
        $this->line('METRO -> KILO   '.$l->material->nombre);
        $this->line('  '.$l->cantidad.' '.$l->unidadVenta->codigo.' x '.$l->factor_conversion.' = '.$l->cantidad_facturar.' '.$l->unidadFactura->codigo);
        $this->line('  x '.$l->precio_por_kilo.' por kilo = '.$l->importe.'   (precio por metro calculado: '.$l->precio_unitario.')');
        $this->newLine();

        $c = Consulta::with('lineas')->find($l->consulta_id);
        $this->line('CONSULTA '.$c->id.' | total '.$c->total.' | iguales a lo pedido: '.$c->lineas_iguales_a_lo_pedido.' | vence '.$c->vence_el->format('d/m/Y'));

        foreach ($c->lineas as $ln) {
            $this->line('  '.$ln->orden.'. '.($ln->igual_a_lo_pedido ? 'igual    ' : 'DISTINTO ').$ln->descripcion);
            if (! $ln->igual_a_lo_pedido) {
                $this->line('       pedido: '.$ln->pedido_texto.'  ->  '.$ln->motivo_cambio);
            }
        }

        $this->newLine();
        $this->line('borradores copiados de RIO TINTO: '.Consulta::whereNotNull('copiada_de_id')->count());

        return self::SUCCESS;
    }
}
