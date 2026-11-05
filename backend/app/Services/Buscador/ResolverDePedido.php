<?php

namespace App\Services\Buscador;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * Del borrador de una búsqueda —lo que entendió el intérprete, con nombres: "Córdoba",
 * "dólares", "el año pasado"— al pedido del Buscador, con los datos que el Buscador
 * entiende: el número de la provincia, las fechas, el nombre exacto de una lista.
 *
 * Es el único camino entre lo que dice una IA y lo que se le pide a la base: todo lo que
 * llega se mira, se pasa por las mismas reglas de validación de Filtros y lo que no sirve
 * se descarta (y se avisa) en vez de adivinarse. Una IA puede equivocarse al interpretar,
 * pero no puede pedir un filtro que no existe ni un valor fuera de lugar.
 */
final class ResolverDePedido
{
    /** Los nombres de lo que se puede pedir, con el borrador de cada uno. */
    public const CLAVES = [
        'vista', 'texto', 'empresa', 'relacion', 'rubro', 'provincia', 'localidad', 'activa',
        'con_whatsapp', 'con_mail', 'sin_cotizar_meses', 'condicion_iva', 'condicion_pago', 'observacion',
        'tipo', 'estado', 'quien', 'moneda', 'periodo', 'desde', 'hasta', 'fecha_de', 'compra',
        'orden_de_compra', 'sin_respuesta', 'por_vencer_dias', 'incluir_borradores',
        'material', 'forma', 'diametro_desde', 'diametro_hasta', 'incluir_no_cotizadas',
        'comprobante', 'tipo_comprobante', 'orden',
        // Ids que devolvió una herramienta de Cordes IA: filtran sin adivinar por nombre.
        'empresa_id', 'material_id', 'forma_id', 'provincia_id', 'localidad_id', 'rubro_id', 'usuario_id', 'moneda_id',
    ];

    private const RELACIONES = [
        'cliente' => 'Cliente', 'proveedor' => 'Proveedor', 'servicio' => 'Servicio',
        'empleado' => 'Empleado', 'agenda general' => 'Agenda general', 'agenda' => 'Agenda general',
    ];

    public function __construct(private readonly Catalogo $catalogo) {}

    /**
     * @param  array<string, mixed>  $borrador
     * @return array{pedido: array<string, string>, no_entendi: list<string>}
     */
    public function resolver(array $borrador): array
    {
        $p = [];
        $no = [];

        $texto = function (string $clave, int $max = 120) use ($borrador): ?string {
            $v = $borrador[$clave] ?? null;
            $v = is_scalar($v) ? trim((string) $v) : '';

            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $si = fn (string $clave): bool => filter_var($borrador[$clave] ?? false, FILTER_VALIDATE_BOOLEAN)
            || in_array(Texto::normalizar((string) (is_scalar($borrador[$clave] ?? null) ? $borrador[$clave] : '')), ['si', 'sí'], true);
        $entero = function (string $clave, int $min, int $max) use ($borrador): ?int {
            $v = $borrador[$clave] ?? null;

            return is_numeric($v) && (int) $v >= $min && (int) $v <= $max ? (int) $v : null;
        };
        $no_se_entendio = function (string $que, string $valor) use (&$no): void {
            $no[] = "{$que} «{$valor}»";
        };

        foreach ((array) ($borrador['no_entendi'] ?? []) as $fragmento) {
            if (is_scalar($fragmento) && trim((string) $fragmento) !== '') {
                $no[] = mb_substr(trim((string) $fragmento), 0, 80);
            }
        }

        /* ---------------------------------------------------------- la empresa */

        if ($v = $texto('texto', 200)) {
            $p['q'] = $v;
        }
        if ($v = $texto('empresa')) {
            $p['empresa'] = $v;
        }
        if ($v = $texto('relacion')) {
            $relacion = $this->buscarPorVariantes(self::RELACIONES, $v);
            $relacion ? $p['relacion'] = $relacion : $no_se_entendio('relación', $v);
        }
        if ($v = $texto('rubro')) {
            ($id = $this->catalogo->rubro($v)) ? $p['rubro_id'] = (string) $id : $no_se_entendio('rubro', $v);
        }
        if ($v = $texto('provincia')) {
            ($id = $this->catalogo->provincia($v)) ? $p['provincia_id'] = (string) $id : $no_se_entendio('provincia', $v);
        }
        if ($v = $texto('localidad')) {
            ($id = $this->catalogo->localidad($v)) ? $p['localidad_id'] = (string) $id : $no_se_entendio('localidad', $v);
        }
        if (in_array($v = Texto::normalizar((string) ($borrador['activa'] ?? '')), ['activas', 'inactivas'], true)) {
            $p['activa'] = $v;
        }
        $si('con_whatsapp') && $p['con_whatsapp'] = '1';
        $si('con_mail') && $p['con_mail'] = '1';
        if (($n = $entero('sin_cotizar_meses', 1, 600)) !== null) {
            $p['sin_cotizar_meses'] = (string) $n;
        }
        if ($v = $texto('condicion_iva')) {
            $iva = $this->condicionDeIva($v);
            $iva ? $p['condicion_iva'] = $iva : $no_se_entendio('condición de IVA', $v);
        }
        if ($v = $texto('condicion_pago')) {
            $p['condicion_pago'] = $v;
        }
        if ($v = $texto('observacion')) {
            $p['observacion'] = $v;
        }

        /* ------------------------------------------------------- la cotización */

        if ($v = $texto('tipo')) {
            $tipo = $this->buscarPorVariantes(['cotizacion' => 'Cotizacion', 'pedido' => 'Pedido', 'observacion' => 'Observacion'], $v);
            $tipo ? $p['tipo'] = $tipo : $no_se_entendio('tipo', $v);
        }
        if ($v = $texto('estado')) {
            ($estado = $this->catalogo->estado($v)) ? $p['estado'] = $estado : $no_se_entendio('estado', $v);
        }
        if ($v = $texto('quien')) {
            ($id = $this->catalogo->usuario($v)) ? $p['usuario_id'] = (string) $id : $no_se_entendio('quién la hizo', $v);
        }
        if ($v = $texto('moneda')) {
            ($id = $this->catalogo->moneda($v)) ? $p['moneda_id'] = (string) $id : $no_se_entendio('moneda', $v);
        }

        [$desde, $hasta] = $this->fechas($borrador);
        $desde && $p['desde'] = $desde;
        $hasta && $p['hasta'] = $hasta;

        if (in_array($v = Texto::normalizar((string) ($borrador['fecha_de'] ?? '')), Filtros::FECHAS, true)) {
            $p['fecha_de'] = $v;
        }
        if (in_array($v = Texto::normalizar((string) ($borrador['compra'] ?? '')), ['vendida', 'parcial', 'total'], true)) {
            $p['compra'] = $v;
        }
        if ($v = $texto('orden_de_compra')) {
            $p['orden_de_compra'] = $v;
        }
        $si('sin_respuesta') && $p['sin_respuesta'] = '1';
        if (($n = $entero('por_vencer_dias', 1, 365)) !== null) {
            $p['por_vencer'] = (string) $n;
        }
        $si('incluir_borradores') && $p['incluir_borradores'] = '1';

        /* ------------------------------------------------------------ el material */

        if ($v = $texto('material')) {
            // Una IA suele escribir "barras de monel": se busca el material del catálogo que nombra.
            // Si no hay ninguno, queda lo escrito.
            $p['material'] = $this->catalogo->materiales()[Texto::normalizar($v)] ?? $this->catalogo->materialEnTexto($v) ?? $v;
        }
        if ($v = $texto('forma')) {
            ($id = $this->catalogo->forma($v)) ? $p['forma_id'] = (string) $id : $no_se_entendio('forma', $v);
        }
        foreach (['diametro_desde', 'diametro_hasta'] as $clave) {
            $n = $borrador[$clave] ?? null;
            // Una IA suele devolver 0 por "no dijo": un diámetro de 0 mm no existe.
            if (is_numeric($n) && (float) $n > 0 && (float) $n < 100000) {
                $p[$clave] = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
            }
        }
        if (isset($p['diametro_desde'], $p['diametro_hasta']) && (float) $p['diametro_desde'] > (float) $p['diametro_hasta']) {
            [$p['diametro_desde'], $p['diametro_hasta']] = [$p['diametro_hasta'], $p['diametro_desde']];
        }
        $si('incluir_no_cotizadas') && $p['incluir_no_cotizadas'] = '1';

        /* -------------------------------------------------------- el comprobante */

        if ($v = $texto('comprobante')) {
            $p['comprobante'] = $v;
        }
        if ($v = $texto('tipo_comprobante')) {
            ($tipo = $this->catalogo->tipoDeComprobante($v)) ? $p['tipo_comprobante'] = $tipo : $no_se_entendio('tipo de comprobante', $v);
        }

        // Un id que ya se sabe manda sobre el nombre (viene de una herramienta, no de una frase).
        foreach (['empresa_id', 'material_id', 'forma_id', 'provincia_id', 'localidad_id', 'rubro_id', 'usuario_id', 'moneda_id'] as $clave) {
            if (($n = $entero($clave, 1, 2_000_000_000)) !== null) {
                $p[$clave] = (string) $n;
            }
        }

        /* ------------------------------------------------- la vista y el orden */

        $vista = Texto::normalizar((string) ($borrador['vista'] ?? ''));
        $vista = in_array($vista, Filtros::GRANOS, true) ? $vista : $this->vistaQueCorresponde($p);
        $vista !== 'empresas' && $p['grano'] = $vista;

        if (in_array($v = Texto::normalizar((string) ($borrador['orden'] ?? '')), ['reciente', 'antiguo', 'nombre', 'importe', 'relevancia'], true)
            && ($v !== 'relevancia' || isset($p['q']))) {
            $p['orden'] = $v;
        }

        // Los comprobantes ya miran la fecha del comprobante: pedirles otra sería mirar una fecha vieja.
        if ($vista === 'comprobantes') {
            unset($p['fecha_de']);
        }

        // Lo que dicen otros campos no se repite como texto: "barras monel" ya es forma y material.
        if (isset($p['q']) && $this->yaLoDicenOtrosCampos($p['q'], $borrador)) {
            unset($p['q']);
        }

        return ['pedido' => $this->validado($p), 'no_entendi' => array_values(array_unique($no))];
    }

    /**
     * Las fechas del borrador: las que se dijeron, o las del período nombrado.
     *
     * @param  array<string, mixed>  $borrador
     * @return array{0: ?string, 1: ?string}
     */
    private function fechas(array $borrador): array
    {
        $dia = function (mixed $v): ?string {
            if (! is_string($v)) {
                return null;
            }
            $f = CarbonImmutable::createFromFormat('!Y-m-d', trim($v));

            return $f !== false && $f->format('Y-m-d') === trim($v) ? $f->toDateString() : null;
        };

        $desde = $dia($borrador['desde'] ?? null);
        $hasta = $dia($borrador['hasta'] ?? null);

        if ($desde === null && $hasta === null && is_string($borrador['periodo'] ?? null)) {
            $periodo = Periodo::de(trim($borrador['periodo']), $this->catalogo->hoy());
            [$desde, $hasta] = $periodo ? [$periodo['desde'], $periodo['hasta']] : [null, null];
        }

        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [$desde, $hasta];
    }

    /**
     * ¿Todas las palabras del texto ya están en el material, la forma, la empresa o el lugar?
     *
     * @param  array<string, mixed>  $borrador
     */
    private function yaLoDicenOtrosCampos(string $texto, array $borrador): bool
    {
        $dichas = [];

        foreach (['material', 'forma', 'empresa', 'provincia', 'localidad', 'rubro'] as $campo) {
            if (is_string($borrador[$campo] ?? null)) {
                $dichas = [...$dichas, ...explode(' ', Texto::normalizar($borrador[$campo]))];
            }
        }

        $palabras = array_filter(explode(' ', Texto::normalizar($texto)), fn ($w) => ! in_array($w, ['de', 'del', 'la', 'el', 'los', 'las', 'y'], true));

        if ($dichas === [] || $palabras === []) {
            return false;
        }

        foreach ($palabras as $palabra) {
            $esta = false;
            foreach ($dichas as $dicha) {
                if ($dicha !== '' && Texto::mismaFrase($palabra, $dicha)) {
                    $esta = true;
                    break;
                }
            }
            if (! $esta) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $opciones  clave en singular => valor
     */
    private function buscarPorVariantes(array $opciones, string $texto): ?string
    {
        foreach ($opciones as $clave => $valor) {
            if (Texto::mismaFrase($clave, Texto::normalizar($texto))) {
                return $valor;
            }
        }

        return null;
    }

    /** La condición frente al IVA como la guarda la FICHA DE LA EMPRESA (Empresa::CONDICIONES_IVA), no la de las razones sociales. */
    private function condicionDeIva(string $texto): ?string
    {
        $t = Texto::normalizar($texto);

        return match (true) {
            str_contains($t, 'inscript') => 'Responsable Inscripto',
            str_contains($t, 'monotrib') => 'Monotributo',
            str_contains($t, 'exent') => 'Exento',
            str_contains($t, 'consumidor') => 'Consumidor Final',
            str_contains($t, 'liberad') => 'Liberado',
            str_contains($t, 'exportaci') => 'Exportación',
            default => null,
        };
    }

    /**
     * Cuando nadie dijo qué se quiere ver, lo dicen los filtros: de un comprobante, el
     * comprobante; de una cotización, las cotizaciones; de un material, lo cotizado.
     *
     * @param  array<string, string>  $p
     */
    public function vistaQueCorresponde(array $p): string
    {
        $tiene = fn (string ...$claves) => array_intersect_key($p, array_flip($claves)) !== [];

        return match (true) {
            $tiene('comprobante', 'tipo_comprobante') => 'comprobantes',
            $tiene('sin_respuesta', 'por_vencer', 'compra', 'orden_de_compra', 'estado', 'tipo', 'incluir_borradores', 'usuario_id', 'moneda_id') => 'consultas',
            $tiene('material', 'material_id', 'forma_id', 'diametro_desde', 'diametro_hasta', 'incluir_no_cotizadas') => 'renglones',
            // Sólo se dijo cuándo: lo que se movió en ese período.
            $tiene('desde', 'hasta') => 'consultas',
            default => 'empresas',
        };
    }

    /**
     * Sólo queda lo que Filtros acepta, valor por valor: uno inválido no tira abajo a los otros.
     *
     * @param  array<string, string>  $p
     * @return array<string, string>
     */
    private function validado(array $p): array
    {
        $reglas = Filtros::reglas();
        $limpio = [];

        foreach ($p as $clave => $valor) {
            if (in_array($clave, ['pagina', 'por_pagina'], true) || ! isset($reglas[$clave])) {
                continue;
            }

            if (Validator::make([$clave => $valor], [$clave => $reglas[$clave]])->passes()) {
                $limpio[$clave] = (string) $valor;
            }
        }

        return $limpio;
    }
}
