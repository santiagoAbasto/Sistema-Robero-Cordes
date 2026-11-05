<?php

namespace App\Services\Reportes;

use Illuminate\Http\Request;

/**
 * Lo que se le pide a un reporte, ya validado. Todos los filtros son opcionales: sin fechas,
 * el reporte mira todo lo que hay.
 */
final class FiltrosDeReporte
{
    /** @param  array<string, mixed>  $d */
    private function __construct(
        public readonly ?string $desde,
        public readonly ?string $hasta,
        public readonly ?int $empresaId,
        public readonly ?string $empresa,
        public readonly ?int $usuarioId,
        public readonly ?int $monedaId,
        public readonly ?int $materialId,
        public readonly ?string $material,
        public readonly ?int $formaId,
        /** "empresa" o "anio": cómo se agrupa el reporte de empresas. */
        public readonly string $agrupar,
        /** "empresas", "materiales" o "usuarios": qué se rankea. */
        public readonly string $por,
        /** "cotizaciones", "vendidas", "cotizado" o "vendido": por qué se ordena el ranking. */
        public readonly string $medida,
        public readonly int $top,
    ) {}

    /** @return array<string, mixed> */
    public static function reglas(): array
    {
        $id = ['nullable', 'integer', 'min:1'];

        return [
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'empresa_id' => $id,
            'empresa' => ['nullable', 'string', 'max:120'],
            'usuario_id' => $id,
            'moneda_id' => $id,
            'material_id' => $id,
            'material' => ['nullable', 'string', 'max:120'],
            'forma_id' => $id,
            'agrupar' => ['nullable', 'in:empresa,anio'],
            'por' => ['nullable', 'in:empresas,materiales,usuarios'],
            'medida' => ['nullable', 'in:cotizaciones,vendidas,cotizado,vendido'],
            'top' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    public static function desdeElPedido(Request $request): self
    {
        $d = $request->validate(self::reglas(), [
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a la fecha "desde".',
            'top.max' => 'Se pueden pedir hasta 500 filas.',
        ]);

        $texto = fn (string $k): ?string => isset($d[$k]) && trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;
        $entero = fn (string $k): ?int => isset($d[$k]) && $d[$k] !== '' ? (int) $d[$k] : null;

        return new self(
            desde: $texto('desde'),
            hasta: $texto('hasta'),
            empresaId: $entero('empresa_id'),
            empresa: $texto('empresa'),
            usuarioId: $entero('usuario_id'),
            monedaId: $entero('moneda_id'),
            materialId: $entero('material_id'),
            material: $texto('material'),
            formaId: $entero('forma_id'),
            agrupar: $d['agrupar'] ?? 'empresa',
            por: $d['por'] ?? 'empresas',
            medida: $d['medida'] ?? 'cotizaciones',
            top: $entero('top') ?? 25,
        );
    }

    /** @param  array<string, mixed>  $d */
    public static function deArray(array $d): self
    {
        $r = new Request($d);

        return self::desdeElPedido($r);
    }
}
