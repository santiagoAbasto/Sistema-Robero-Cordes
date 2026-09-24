<?php

namespace App\Http\Controllers;

use App\Models\ConsultaLineaOpcion;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el sistema propone al armar alternativas.
 *
 * La idea es no volver a escribir lo mismo. Si las ultimas veces el aereo
 * salio a 40 dias y el maritimo a 80, la proxima vez ya vienen puestos: se
 * corrigen si cambiaron, pero no hay que acordarse ni buscarlos.
 *
 * Los plazos salen SOLO del historial. Cuando no hay historial se proponen las
 * etiquetas y el plazo queda en blanco: un plazo inventado se copia a una
 * cotizacion y se convierte en un compromiso que nadie asumio.
 */
class AlternativaController extends Controller
{
    /** Las dos vias son siempre las mismas dos: eso no hace falta aprenderlo. */
    private const TRANSPORTE = ['Maritimo', 'Aereo'];

    public function sugerencias()
    {
        return [
            'transporte' => $this->transporte(),
            'tipos' => ConsultaLineaOpcion::TIPOS,
        ];
    }

    /**
     * Las vias, con el plazo que se uso la ultima vez.
     *
     * @return list<array{etiqueta: string, tipo: string, plazo_dias: ?int, veces: int}>
     */
    private function transporte(): array
    {
        // El ultimo plazo cargado para cada etiqueta, y cuantas veces se uso.
        $historial = ConsultaLineaOpcion::query()
            ->where('tipo', 'Transporte')
            ->whereNotNull('plazo_dias')
            ->select('etiqueta', DB::raw('count(*) as veces'), DB::raw('max(id) as ultimo'))
            ->groupBy('etiqueta')
            ->get()
            ->keyBy(fn ($f) => mb_strtolower($f->etiqueta));

        $plazos = ConsultaLineaOpcion::whereIn('id', $historial->pluck('ultimo'))
            ->pluck('plazo_dias', 'id');

        return collect(self::TRANSPORTE)
            ->map(function (string $etiqueta) use ($historial, $plazos) {
                $fila = $historial[mb_strtolower($etiqueta)] ?? null;

                return [
                    'etiqueta' => $etiqueta,
                    'tipo' => 'Transporte',
                    'plazo_dias' => $fila ? (int) $plazos[$fila->ultimo] : null,
                    'veces' => $fila ? (int) $fila->veces : 0,
                ];
            })
            ->all();
    }
}
