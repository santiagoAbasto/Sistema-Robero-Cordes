<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los números de la pantalla de inicio.
 *
 * Todo sale de lo que hay cargado. Cuando algo todavía no se puede saber
 * —las compras, por ejemplo, que son de otro módulo— no se muestra: es
 * preferible una tarjeta menos que un número inventado.
 */
class ResumenController extends Controller
{
    public function index()
    {
        $hoy = Carbon::today();
        $desdeMes = $hoy->copy()->startOfMonth();
        $desdeMesPasado = $desdeMes->copy()->subMonth();

        $cotizacionesMes = Consulta::cotizaciones()
            ->whereBetween('fecha', [$desdeMes, $hoy])
            ->count();

        $cotizacionesMesPasado = Consulta::cotizaciones()
            ->whereBetween('fecha', [$desdeMesPasado, $desdeMes->copy()->subDay()])
            ->count();

        return response()->json([
            'periodo' => $this->mes($hoy),
            'totales' => [
                'empresas' => Empresa::activas()->count(),
                'empresas_nuevas' => Empresa::activas()->where('created_at', '>=', $desdeMes)->count(),
                'clientes' => Empresa::activas()->conRelacion('Cliente')->count(),
                'proveedores' => Empresa::activas()->conRelacion('Proveedor')->count(),
                'cotizaciones_mes' => $cotizacionesMes,
                'cotizaciones_mes_pasado' => $cotizacionesMesPasado,
            ],
            'ultimas' => $this->ultimasCotizaciones(),
            'materiales' => $this->materialesMasCotizados($hoy),
        ]);
    }

    /** Las últimas que se cargaron, sin importar de quién sean. */
    private function ultimasCotizaciones(): array
    {
        // Las lineas van si o si: sin ellas el total da cero, porque el modelo
        // no las va a buscar de a una para no hacer una consulta por fila.
        return Consulta::with([
            'empresa:id,nombre',
            'moneda:id,nombre,moneda_base',
            'lineas:id,consulta_id,importe,quitada',
        ])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->take(6)
            ->get()
            ->map(fn (Consulta $c) => [
                'id' => $c->id,
                'empresa_id' => $c->empresa_id,
                'empresa' => $c->empresa?->nombre,
                'fecha' => $c->fecha?->toDateString(),
                'tipo' => $c->tipo,
                'estado' => $c->estado,
                'moneda' => $c->moneda?->nombre,
                // "Dolar", "Euro", "Peso argentino": el nombre completo no
                // entra en la columna de la tabla.
                'moneda_base' => $c->moneda?->moneda_base,
                'total' => round($c->total, 2),
            ])
            ->all();
    }

    /**
     * Lo que más se pidió en los últimos 30 días.
     *
     * Ocupa el lugar donde iban los proveedores más usados: eso necesita las
     * compras, que son de otro módulo.
     */
    private function materialesMasCotizados(Carbon $hoy): array
    {
        $filas = ConsultaLinea::query()
            ->join('consultas', 'consultas.id', '=', 'consulta_lineas.consulta_id')
            ->join('materiales', 'materiales.id', '=', 'consulta_lineas.material_id')
            ->where('consultas.fecha', '>=', $hoy->copy()->subDays(30))
            ->where('consulta_lineas.quitada', false)
            ->groupBy('materiales.id', 'materiales.nombre')
            ->orderByDesc('veces')
            ->take(5)
            ->get(['materiales.nombre', DB::raw('count(*) as veces')]);

        return $filas->map(fn ($f) => [
            'nombre' => $f->nombre,
            'veces' => (int) $f->veces,
        ])->all();
    }

    private function mes(Carbon $fecha): string
    {
        $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        return $meses[$fecha->month - 1].' '.$fecha->year;
    }
}
