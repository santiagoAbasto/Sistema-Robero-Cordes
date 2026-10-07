<?php

namespace App\Http\Controllers;

use App\Models\ConsultaLinea;

/**
 * Lo que el sistema propone al armar alternativas.
 *
 * La idea es no volver a escribir lo mismo. Si las ultimas veces el aereo
 * salio a 40 dias y el maritimo a 80, la proxima vez ya vienen puestos: se
 * corrigen si cambiaron, pero no hay que acordarse ni buscarlos.
 *
 * Los plazos salen SOLO del historial. Cuando no hay historial el plazo queda
 * en blanco: un plazo inventado se copia a una cotizacion y se convierte en un
 * compromiso que nadie asumio.
 */
class AlternativaController extends Controller
{
    public function sugerencias()
    {
        return ['transporte' => $this->transporte()];
    }

    /**
     * Cada via, con el plazo de la ultima linea que la uso.
     *
     * @return list<array{etiqueta: string, plazo_dias: ?int}>
     */
    private function transporte(): array
    {
        return collect(ConsultaLinea::TRANSPORTES)
            ->map(fn (string $via) => [
                'etiqueta' => $via,
                'plazo_dias' => ConsultaLinea::where('transporte', $via)
                    ->whereNotNull('plazo_dias')
                    ->latest('id')
                    ->value('plazo_dias'),
            ])
            ->all();
    }
}
