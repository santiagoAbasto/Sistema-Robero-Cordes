<?php

namespace App\Services\Buscador;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Lo que se le pide al buscador único, ya validado y ordenado.
 *
 * Hay CUATRO vistas del mismo dato —empresas, cotizaciones, renglones y
 * comprobantes— y los filtros se dividen en cuatro grupos, uno por cosa que
 * se puede mirar. Un filtro de un grupo vale en cualquier vista: en "empresas",
 * "material = monel" quiere decir "las empresas a las que les cotizamos monel";
 * en "renglones", "los renglones de monel". Por eso cada grupo se escribe una
 * sola vez (ver Buscador) y esta clase sólo dice cuáles están en uso.
 */
final class Filtros
{
    public const GRANOS = ['empresas', 'consultas', 'renglones', 'comprobantes'];

    /** Qué fecha de la cotización se usa para desde/hasta. */
    public const FECHAS = ['cotizacion', 'factura', 'remito', 'pedido', 'comprobante'];

    /** @param  list<string>  $palabras */
    private function __construct(
        public readonly string $grano,
        public readonly ?string $q,
        public readonly array $palabras,
        public readonly int $pagina,
        public readonly int $porPagina,
        public readonly string $orden,
        // Empresa
        public readonly ?int $empresaId,
        public readonly ?string $empresa,
        public readonly ?string $relacion,
        public readonly ?int $rubroId,
        public readonly ?int $provinciaId,
        public readonly ?int $localidadId,
        public readonly string $activa,
        public readonly bool $conWhatsapp,
        public readonly bool $conMail,
        public readonly ?int $sinCotizarMeses,
        public readonly ?string $condicionIva,
        public readonly ?string $condicionPago,
        public readonly ?string $condicion,
        public readonly ?int $condicionId,
        public readonly ?int $opcionId,
        public readonly ?string $observacion,
        // Cotización
        public readonly ?string $tipo,
        public readonly ?string $estado,
        public readonly ?int $usuarioId,
        public readonly ?int $monedaId,
        public readonly ?string $desde,
        public readonly ?string $hasta,
        public readonly string $fechaDe,
        public readonly ?string $compra,
        public readonly ?string $ordenDeCompra,
        public readonly bool $incluirBorradores,
        public readonly bool $sinRespuesta,
        public readonly ?int $porVencer,
        // Renglón
        public readonly ?int $materialId,
        public readonly ?string $material,
        public readonly ?int $formaId,
        public readonly ?float $diametroDesde,
        public readonly ?float $diametroHasta,
        public readonly bool $incluirNoCotizadas,
        // Comprobante
        public readonly ?string $comprobante,
        public readonly ?string $tipoComprobante,
    ) {}

    /** @return array<string, mixed> las reglas de validación del pedido */
    public static function reglas(): array
    {
        $texto = ['nullable', 'string', 'max:120'];
        $id = ['nullable', 'integer', 'min:1'];

        return [
            'grano' => ['nullable', 'in:'.implode(',', self::GRANOS)],
            'q' => ['nullable', 'string', 'max:200'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'orden' => ['nullable', 'in:relevancia,reciente,antiguo,nombre,importe'],

            'empresa_id' => $id,
            'empresa' => $texto,
            'relacion' => ['nullable', 'in:Cliente,Proveedor,Servicio,Empleado,Agenda general'],
            'rubro_id' => $id,
            'provincia_id' => $id,
            'localidad_id' => $id,
            'activa' => ['nullable', 'in:todas,activas,inactivas'],
            'con_whatsapp' => ['nullable', 'boolean'],
            'con_mail' => ['nullable', 'boolean'],
            'sin_cotizar_meses' => ['nullable', 'integer', 'min:1', 'max:600'],
            'condicion_iva' => ['nullable', 'string', 'max:30'],
            'condicion_pago' => $texto,
            'condicion' => $texto,
            'condicion_id' => $id,
            'opcion_id' => $id,
            'observacion' => $texto,

            'tipo' => ['nullable', 'in:Cotizacion,Pedido,Observacion'],
            'estado' => ['nullable', 'string', 'max:40'],
            'usuario_id' => $id,
            'moneda_id' => $id,
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'fecha_de' => ['nullable', 'in:'.implode(',', self::FECHAS)],
            'compra' => ['nullable', 'in:vendida,parcial,total'],
            'orden_de_compra' => $texto,
            'incluir_borradores' => ['nullable', 'boolean'],
            'sin_respuesta' => ['nullable', 'boolean'],
            'por_vencer' => ['nullable', 'integer', 'min:1', 'max:365'],

            'material_id' => $id,
            'material' => $texto,
            'forma_id' => $id,
            'diametro_desde' => ['nullable', 'numeric', 'min:0'],
            'diametro_hasta' => ['nullable', 'numeric', 'min:0'],
            'incluir_no_cotizadas' => ['nullable', 'boolean'],

            'comprobante' => $texto,
            'tipo_comprobante' => $texto,
        ];
    }

    /** Valida el pedido y lo convierte. */
    /**
     * Sólo queda lo que el buscador acepta, valor por valor: uno inválido no tira abajo a los otros.
     * Es el único lugar donde un pedido que viene de afuera (la IA, un atajo, la pantalla) se limpia.
     *
     * @param  array<string, mixed>  $pedido
     * @return array<string, string>
     */
    public static function sanear(array $pedido): array
    {
        $reglas = self::reglas();
        $limpio = [];

        foreach ($pedido as $clave => $valor) {
            if (! is_string($clave) || ! is_scalar($valor) || in_array($clave, ['pagina', 'por_pagina'], true) || ! isset($reglas[$clave])) {
                continue;
            }

            if (Validator::make([$clave => $valor], [$clave => $reglas[$clave]])->passes()) {
                $limpio[$clave] = (string) $valor;
            }
        }

        return $limpio;
    }

    public static function desde(Request $request): self
    {
        return self::deArray($request->validate(self::reglas(), self::mensajes()));
    }

    /** @param  array<string, mixed>  $d */
    public static function deArray(array $d): self
    {
        $texto = fn (string $k): ?string => isset($d[$k]) && trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;
        $entero = fn (string $k): ?int => isset($d[$k]) && $d[$k] !== '' ? (int) $d[$k] : null;
        $numero = fn (string $k): ?float => isset($d[$k]) && $d[$k] !== '' ? (float) $d[$k] : null;
        $si = fn (string $k): bool => filter_var($d[$k] ?? false, FILTER_VALIDATE_BOOLEAN);

        $grano = $d['grano'] ?? 'empresas';
        $q = $texto('q');

        return new self(
            grano: $grano,
            q: $q,
            // Cada palabra tiene que aparecer en algún lado, en cualquier orden.
            palabras: $q === null ? [] : array_values(array_unique(preg_split('/\s+/u', $q) ?: [])),
            pagina: $entero('pagina') ?? 1,
            porPagina: $entero('por_pagina') ?? 25,
            // Empresas: por relevancia si se escribió algo y, si no, alfabético como una guía telefónica.
            // Lo demás, lo más nuevo primero.
            orden: $d['orden'] ?? ($grano === 'empresas' ? ($q !== null ? 'relevancia' : 'nombre') : 'reciente'),
            empresaId: $entero('empresa_id'),
            empresa: $texto('empresa'),
            relacion: $texto('relacion'),
            rubroId: $entero('rubro_id'),
            provinciaId: $entero('provincia_id'),
            localidadId: $entero('localidad_id'),
            // Las inactivas SE VEN (al final y en rojo): sólo se esconden si se pide.
            activa: $d['activa'] ?? 'todas',
            conWhatsapp: $si('con_whatsapp'),
            conMail: $si('con_mail'),
            sinCotizarMeses: $entero('sin_cotizar_meses'),
            condicionIva: $texto('condicion_iva'),
            condicionPago: $texto('condicion_pago'),
            condicion: $texto('condicion'),
            condicionId: $entero('condicion_id'),
            opcionId: $entero('opcion_id'),
            observacion: $texto('observacion'),
            tipo: $texto('tipo'),
            estado: $texto('estado'),
            usuarioId: $entero('usuario_id'),
            monedaId: $entero('moneda_id'),
            desde: $texto('desde'),
            hasta: $texto('hasta'),
            // En los comprobantes la fecha que importa es la del comprobante.
            fechaDe: $d['fecha_de'] ?? ($grano === 'comprobantes' ? 'comprobante' : 'cotizacion'),
            compra: $texto('compra'),
            ordenDeCompra: $texto('orden_de_compra'),
            incluirBorradores: $si('incluir_borradores'),
            sinRespuesta: $si('sin_respuesta'),
            porVencer: $entero('por_vencer'),
            materialId: $entero('material_id'),
            material: $texto('material'),
            formaId: $entero('forma_id'),
            diametroDesde: $numero('diametro_desde'),
            diametroHasta: $numero('diametro_hasta'),
            incluirNoCotizadas: $si('incluir_no_cotizadas'),
            comprobante: $texto('comprobante'),
            tipoComprobante: $texto('tipo_comprobante'),
        );
    }

    /** ¿Hay algo que mirar de la EMPRESA? (la inactiva sola no cuenta: es lo que se ve por defecto) */
    public function hayDeEmpresa(): bool
    {
        return $this->empresaId !== null || $this->empresa !== null || $this->relacion !== null
            || $this->rubroId !== null || $this->provinciaId !== null || $this->localidadId !== null
            || $this->activa !== 'todas' || $this->conWhatsapp || $this->conMail
            || $this->sinCotizarMeses !== null || $this->condicionIva !== null
            || $this->condicionPago !== null || $this->condicion !== null || $this->observacion !== null
            || $this->condicionId !== null || $this->opcionId !== null;
    }

    /** ¿Hay algo que mirar de la COTIZACIÓN? Las fechas del comprobante son del comprobante. */
    public function hayDeConsulta(): bool
    {
        return $this->tipo !== null || $this->estado !== null || $this->usuarioId !== null
            || $this->monedaId !== null || $this->compra !== null || $this->ordenDeCompra !== null
            || $this->sinRespuesta || $this->porVencer !== null
            || ($this->fechaDe !== 'comprobante' && ($this->desde !== null || $this->hasta !== null));
    }

    /** ¿Hay algo que mirar del RENGLÓN? */
    public function hayDeRenglon(): bool
    {
        return $this->materialId !== null || $this->material !== null || $this->formaId !== null
            || $this->diametroDesde !== null || $this->diametroHasta !== null;
    }

    /** ¿Hay algo que mirar del COMPROBANTE? */
    public function hayDeComprobante(): bool
    {
        return $this->comprobante !== null || $this->tipoComprobante !== null
            || ($this->fechaDe === 'comprobante' && ($this->desde !== null || $this->hasta !== null) && $this->grano !== 'comprobantes');
    }

    /** @return array<string, string> */
    private static function mensajes(): array
    {
        return [
            'grano.in' => 'La vista tiene que ser empresas, consultas, renglones o comprobantes.',
            'por_pagina.max' => 'Se pueden pedir hasta 100 resultados por página.',
            'orden.in' => 'El orden tiene que ser relevancia, reciente, antiguo, nombre o importe.',
            'desde.date' => 'La fecha "desde" no es válida.',
            'hasta.date' => 'La fecha "hasta" no es válida.',
        ];
    }
}
