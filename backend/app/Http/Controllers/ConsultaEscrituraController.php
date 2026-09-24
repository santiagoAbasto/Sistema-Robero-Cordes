<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConsultaResource;
use App\Models\CanoEstandar;
use App\Models\CondicionHabitual;
use App\Models\Consulta;
use App\Models\ConsultaCondicion;
use App\Models\ConsultaLinea;
use App\Models\ConsultaLineaOpcion;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Impresion;
use App\Models\Material;
use App\Models\Observacion;
use App\Services\CalculadoraDePeso;
use App\Services\CalculadoraFactor;
use App\Services\InterpreteIA;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Carga y modificación de cotizaciones, pedidos y observaciones. */
class ConsultaEscrituraController extends Controller
{
    public function store(Request $request, Empresa $empresa)
    {
        $this->soloSiPuedeModificar($request);
        $datos = $this->validar($request);

        $consulta = DB::transaction(function () use ($empresa, $datos, $request) {
            $consulta = $empresa->consultas()->make($this->soloCabecera($datos));
            $consulta->usuario_id = $request->user()->id;
            $consulta->validez_dias = $datos['validez_dias'] ?? Consulta::VALIDEZ_POR_DEFECTO;
            $consulta->recalcularVencimiento();
            $consulta->save();

            $this->guardarLineas($consulta, $datos['lineas'] ?? []);

            // Si no mandaron condiciones, entran las del juego elegido. Un
            // array vacio explicito es otra cosa: alguien las saco a proposito.
            $this->guardarCondiciones($consulta, array_key_exists('condiciones', $datos)
                ? $datos['condiciones']
                : $this->condicionesPorDefecto($consulta));

            return $consulta;
        });

        return (new ConsultaResource($this->recargar($consulta)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Consulta $consulta)
    {
        $this->soloSiPuedeModificar($request);
        $datos = $this->validar($request, $consulta);

        DB::transaction(function () use ($consulta, $datos) {
            $consulta->fill($this->soloCabecera($datos));

            if (array_key_exists('validez_dias', $datos)) {
                $consulta->validez_dias = $datos['validez_dias'];
            }

            $consulta->recalcularVencimiento();
            $consulta->save();

            if (array_key_exists('lineas', $datos)) {
                $this->guardarLineas($consulta, $datos['lineas']);
            }

            if (array_key_exists('condiciones', $datos)) {
                $consulta->condiciones()->delete();
                $this->guardarCondiciones($consulta, $datos['condiciones']);
            }
        });

        return new ConsultaResource($this->recargar($consulta));
    }

    /**
     * Las descripciones que aparecen mas de una vez.
     *
     * La lectura con IA a veces devuelve un renglon repetido: el mismo pedido
     * sale dos veces y la cotizacion terminaria con un item que el cliente no
     * pidio. No se borra ninguna —el cliente PUEDE haber pedido dos iguales—,
     * pero se avisa para que alguien mire antes de guardar.
     *
     * @return array<int, string>
     */
    private function descripcionesRepetidas(array $lineas): array
    {
        return collect($lineas)
            ->pluck('descripcion')
            ->map(fn ($d) => mb_strtolower(trim((string) $d)))
            ->filter()
            ->countBy()
            ->filter(fn ($veces) => $veces > 1)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * Quien no puede modificar, no modifica.
     *
     * El permiso se marca en "Quien ve que". Estaba guardado pero no lo miraba
     * nadie: la pantalla prometia un control que no existia.
     */
    private function soloSiPuedeModificar(Request $request): void
    {
        abort_unless(
            $request->user()?->puedeModificarCotizaciones() ?? false,
            403,
            'No tenés permiso para modificar cotizaciones. Pedíselo a un administrador.',
        );
    }

    /**
     * Copiar una cotización a otras empresas.
     *
     * Se copian las líneas y los precios. La condición de pago, la lista de
     * precios y el contacto NO se copian: salen de la ficha de cada empresa
     * destino, porque una puede tener crédito y las otras no.
     */
    public function copiar(Request $request, Consulta $consulta)
    {
        $this->soloSiPuedeModificar($request);

        $datos = $request->validate([
            'empresas' => ['required', 'array', 'min:1'],
            'empresas.*' => ['exists:empresas,id'],
        ]);

        $consulta->load('lineas', 'condiciones');

        $borradores = DB::transaction(function () use ($consulta, $datos, $request) {
            $creados = [];

            foreach ($datos['empresas'] as $empresaId) {
                $destino = Empresa::with('campos', 'contactos')->find($empresaId);

                if (! $destino) {
                    continue;
                }

                $borrador = $destino->consultas()->create([
                    'tipo' => $consulta->tipo,
                    'fecha' => now()->toDateString(),
                    'validez_dias' => Consulta::VALIDEZ_POR_DEFECTO,
                    'contacto_id' => $destino->contactoPrincipal()?->id,
                    'razon_social_id' => $destino->razonSocialHabitual()?->id,
                    'usuario_id' => $request->user()->id,
                    'moneda_id' => $consulta->moneda_id,
                    'tipo_cambio' => $consulta->tipo_cambio,
                    // De la ficha del destino, no de la original.
                    'condicion_pago' => $destino->campos->firstWhere('titulo', 'Tipo de pago')?->valor,
                    'lista_precios' => $destino->campos->firstWhere('titulo', 'Lista de precios')?->valor,
                    'estado' => 'Borrador',
                    'juego_condiciones' => $consulta->juego_condiciones,
                    'copiada_de_id' => $consulta->id,
                    // La NOTA y las observaciones de la original no se copian.
                ]);

                $borrador->recalcularVencimiento();
                $borrador->save();

                foreach ($consulta->lineas as $linea) {
                    $copia = $linea->replicate(['id', 'consulta_id', 'created_at', 'updated_at']);
                    $copia->consulta_id = $borrador->id;

                    // Se copian los datos tecnicos, no los precios: cada empresa
                    // tiene su lista y su condicion. Los precios se ponen al abrir
                    // el borrador.
                    $copia->precio_unitario = null;
                    $copia->precio_por_kilo = null;
                    $copia->importe = null;

                    $copia->save();
                }

                foreach ($consulta->condiciones as $condicion) {
                    ConsultaCondicion::create([
                        'consulta_id' => $borrador->id,
                        'orden' => $condicion->orden,
                        'titulo' => $condicion->titulo,
                        'texto' => $condicion->texto,
                        'imprime' => $condicion->imprime,
                        'origen' => $condicion->origen,
                    ]);
                }

                $creados[] = $borrador;
            }

            return $creados;
        });

        return response()->json([
            'mensaje' => count($borradores).' borradores creados. Ninguno se manda hasta confirmarlo.',
            'borradores' => ConsultaResource::collection(
                // Las condiciones se copian y hay que devolverlas: sin esto el
                // borrador se ve sin condiciones aunque las tenga guardadas.
                Consulta::with(['empresa', 'contacto', 'lineas.material', 'lineas.forma',
                    'lineas.unidadVenta', 'lineas.unidadFactura', 'usuario', 'moneda', 'condiciones'])
                    ->whereIn('id', collect($borradores)->pluck('id'))->get()
            ),
        ], 201);
    }

    /** Pasa un borrador a cotización firme. */
    public function confirmar(Request $request, Consulta $consulta)
    {
        $this->soloSiPuedeModificar($request);

        $consulta->update(['estado' => 'Confirmada']);

        return new ConsultaResource($this->recargar($consulta));
    }

    public function destroy(Request $request, Consulta $consulta)
    {
        $this->soloSiPuedeModificar($request);

        // Sólo se descarta lo que todavía es borrador. Lo confirmado no se borra.
        if ($consulta->estado !== 'Borrador') {
            return response()->json([
                'message' => 'Sólo se pueden descartar borradores. Una cotización confirmada no se borra.',
            ], 422);
        }

        $consulta->anotarCambio('Archivado', 'estado', 'Borrador', 'descartado');
        $consulta->delete();

        return response()->json(['mensaje' => 'Borrador descartado.']);
    }

    /**
     * Precarga: se pega el texto que mandó el cliente y el sistema propone
     * las líneas ya separadas. Nada se guarda: es para revisar y corregir.
     */
    public function interpretar(Request $request, InterpreteIA $interprete)
    {
        $datos = $request->validate([
            'texto' => ['required', 'string', 'min:5'],
        ], [
            'texto.required' => 'Pega el texto del mail o del WhatsApp y lo separamos en lineas.',
        ]);

        $resultado = $interprete->interpretar($datos['texto']);
        $cuantas = count($resultado['lineas']);

        return response()->json([
            'lineas' => $resultado['lineas'],
            'sin_reconocer' => $resultado['sin_reconocer'],
            'con_ia' => $resultado['con_ia'],
            'aviso' => $resultado['aviso'],
            'repetidas' => $this->descripcionesRepetidas($resultado['lineas']),
            'mensaje' => $cuantas === 0
                ? 'No pudimos reconocer ninguna linea. Cargalas a mano.'
                : $cuantas.' lineas reconocidas. Revisalas antes de guardar.',
        ]);
    }

    // ------------------------------------------------------------- el estado

    /**
     * Cambiar el estado de una cotizacion, diciendo cuando y por que.
     *
     * Un estado suelto no sirve para nada seis meses despues: "cerrada" no
     * dice si el cliente eligio a otro, si se cayo el proyecto o si el precio
     * no daba, y esa es justamente la informacion con la que se vuelve a
     * cotizar. Por eso los dos estados de cierre piden comentario obligatorio.
     *
     * El comentario no se guarda en un campo aparte: entra al hilo de
     * observaciones de la cotizacion, que ya existe, esta numerado y firmado.
     * Asi el cierre queda en la misma conversacion que el resto del
     * seguimiento, en orden, y no en un rincon que nadie lee.
     *
     * Quien cambio el estado y de que a que lo escribe solo RegistraCambios.
     */
    public function cambiarEstado(Request $request, Consulta $consulta)
    {
        $esCierre = in_array($request->input('estado'), Consulta::ESTADOS_DE_CIERRE, true);

        $datos = $request->validate([
            'estado' => ['required', Rule::in(Consulta::ESTADOS)],
            // Al cerrar hay que decir por que. En los demas cambios es opcional.
            'comentario' => [$esCierre ? 'required' : 'nullable', 'string', 'max:2000'],
            // Por defecto hoy, pero se puede fechar cuando paso de verdad: el
            // cliente avisa el lunes algo que decidio el viernes.
            'fecha' => ['nullable', 'date'],
        ], [
            'comentario.required' => 'Para cerrar una cotizacion hay que decir por que.',
        ]);

        $anterior = $consulta->estado;

        DB::transaction(function () use ($consulta, $datos, $anterior, $request) {
            $consulta->estado = $datos['estado'];
            $consulta->save();

            if (blank($datos['comentario'] ?? null)) {
                return;
            }

            Observacion::create([
                'consulta_id' => $consulta->id,
                'numero' => ($consulta->observaciones()->max('numero') ?? 0) + 1,
                'fecha' => $datos['fecha'] ?? now(),
                'usuario_id' => $request->user()->id,
                'texto' => "{$anterior} -> {$datos['estado']}. {$datos['comentario']}",
            ]);
        });

        return [
            'estado' => $consulta->estado,
            'anterior' => $anterior,
            'mensaje' => "Quedo {$consulta->estado}.",
        ];
    }

    // ---------------------------------------------------------- observaciones

    public function agregarObservacion(Request $request, Consulta $consulta)
    {
        $datos = $request->validate(['texto' => ['required', 'string']]);

        $observacion = Observacion::create([
            'consulta_id' => $consulta->id,
            'numero' => ($consulta->observaciones()->max('numero') ?? 0) + 1,
            'fecha' => now(),
            'usuario_id' => $request->user()->id,
            'texto' => $datos['texto'],
        ]);

        return response()->json([
            'id' => $observacion->id,
            'numero' => $observacion->numero,
            'mensaje' => 'Observacion agregada. Es de uso interno: no se imprime.',
        ], 201);
    }

    public function borrarObservacion(Observacion $observacion)
    {
        $observacion->delete();

        return response()->json(['mensaje' => 'Observacion quitada.']);
    }

    // --------------------------------------------------------------- imprimir

    /**
     * Deja registrado con qué datos salió la hoja.
     * Elegir otro contacto acá no toca la ficha: vale sólo para esta impresión.
     */
    public function registrarImpresion(Request $request, Consulta $consulta)
    {
        $datos = $request->validate([
            'nombre_en_pdf' => ['required', 'string', 'max:150'],
            'contacto_id' => ['nullable', 'exists:contactos,id'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'mail' => ['nullable', 'string', 'max:120'],
            'via' => ['required', Rule::in(['Impresora', 'PDF', 'Correo', 'WhatsApp'])],
            'incluye_importes' => ['boolean'],
            'incluye_nota' => ['boolean'],
        ]);

        $impresion = Impresion::create($datos + [
            'consulta_id' => $consulta->id,
            'fecha' => now(),
            'usuario_id' => $request->user()->id,
            'vencida_al_mandar' => $consulta->esta_vencida,
        ]);

        $consulta->anotarCambio(
            'Impresion',
            'via',
            null,
            $datos['via'].' a '.($impresion->contacto?->nombre ?? $datos['nombre_en_pdf'])
        );

        return response()->json([
            'id' => $impresion->id,
            'mensaje' => $consulta->esta_vencida
                ? 'Quedó registrado. Ojo: esta cotización ya estaba vencida.'
                : 'Quedó registrado a quién se le mandó y por qué vía.',
        ], 201);
    }

    // ------------------------------------------------------------- privadas

    private function validar(Request $request, ?Consulta $consulta = null): array
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['Cotizacion', 'Pedido', 'Observacion'])],
            'fecha' => ['required', 'date'],
            'validez_dias' => ['nullable', 'integer', 'min:0', 'max:365'],
            'contacto_id' => ['nullable', 'exists:contactos,id'],
            'razon_social_id' => ['nullable', 'exists:razones_sociales,id'],
            'moneda_id' => ['nullable', 'exists:monedas,id'],
            'tipo_cambio' => ['nullable', 'numeric'],
            'ajuste_dif_cambio' => ['boolean'],
            'ajuste_dif_cambio_detalle' => ['nullable', 'string', 'max:120'],
            'nro_factura' => ['nullable', 'string', 'max:20'],
            'id_sistema' => ['nullable', 'string', 'max:20'],
            'condicion_pago' => ['nullable', 'string', 'max:80'],
            'lista_precios' => ['nullable', 'string', 'max:40'],
            'nota' => ['nullable', 'string'],
            'texto' => ['nullable', 'string'],
            'estado' => ['nullable', Rule::in(Consulta::ESTADOS)],
            'solicitud_via' => ['nullable', Rule::in(['Mail', 'WhatsApp', 'Telefono', 'En persona'])],
            'solicitud_fecha' => ['nullable', 'date'],
            'solicitud_texto' => ['nullable', 'string'],

            'lineas' => ['array'],
            // La linea que ya existia se actualiza en vez de recrearse: asi no
            // pierde de donde vino su factor. El id tiene que ser de ESTA
            // cotizacion: uno ajeno se rechaza, no se ignora en silencio —
            // ignorarlo crearia una linea duplicada sin que nadie se entere.
            'lineas.*.id' => [
                'nullable', 'integer',
                Rule::exists('consulta_lineas', 'id')
                    ->where('consulta_id', $consulta?->id ?? 0),
            ],
            // "Usar estos kilos": el servidor saca el factor y lo aplica. Es
            // una operacion, no un valor — el navegador pide, no decide.
            'lineas.*.aplicar_calculo_al_factor' => ['boolean'],
            'lineas.*.descripcion' => ['required', 'string'],
            // Como el cliente reconoce el item: su codigo de articulo, el
            // numero de item de su requerimiento, y la nota del producto
            // (plano, posicion, tratamiento).
            'lineas.*.codigo_cliente' => ['nullable', 'string', 'max:60'],
            'lineas.*.item_cliente' => ['nullable', 'string', 'max:20'],
            'lineas.*.nota' => ['nullable', 'string', 'max:2000'],
            'lineas.*.material_id' => ['nullable', 'exists:materiales,id'],
            // El material que el cliente pidio y todavia no esta en el
            // catalogo. Se da de alta al guardar. Ver resolverElMaterial().
            'lineas.*.material_nuevo' => ['nullable', 'string', 'max:80'],
            'lineas.*.forma_id' => ['nullable', 'exists:formas,id'],
            'lineas.*.dimensiones' => ['nullable', 'string', 'max:120'],
            'lineas.*.diametro_mm' => ['nullable', 'numeric'],
            'lineas.*.espesor_mm' => ['nullable', 'numeric'],
            'lineas.*.ancho_mm' => ['nullable', 'numeric'],
            // Faltaba: sin el largo no hay factor por pieza, y la pantalla
            // avisaba "falta el largo" con el largo cargado a la vista.
            'lineas.*.largo_mm' => ['nullable', 'numeric'],
            'lineas.*.cantidad' => ['nullable', 'numeric'],
            'lineas.*.unidad_venta_id' => ['nullable', 'exists:unidades,id'],
            'lineas.*.unidad_factura_id' => ['nullable', 'exists:unidades,id'],
            'lineas.*.factor_conversion' => ['nullable', 'numeric'],
            'lineas.*.precio_unitario' => ['nullable', 'numeric'],
            'lineas.*.precio_por_kilo' => ['nullable', 'numeric'],
            'lineas.*.igual_a_lo_pedido' => ['boolean'],
            'lineas.*.motivo_cambio' => ['nullable', 'string', 'max:120'],
            'lineas.*.pedido_material' => ['nullable', 'string', 'max:120'],
            'lineas.*.pedido_forma' => ['nullable', 'string', 'max:60'],
            'lineas.*.pedido_dimensiones' => ['nullable', 'string', 'max:120'],
            'lineas.*.cantidad_pedida' => ['nullable', 'numeric'],
            'lineas.*.unidad_pedida_id' => ['nullable', 'exists:unidades,id'],
            'lineas.*.aprox' => ['boolean'],
            'lineas.*.idem' => ['boolean'],
            'lineas.*.quitada' => ['boolean'],
            'lineas.*.desde_stock' => ['boolean'],

            // La calculadora de peso. Llegan las medidas como las cargaron —con
            // su unidad— y el servidor rehace la cuenta: el peso que se guarda
            // no es el que dijo el navegador.
            'lineas.*.calc_piezas' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.calc_cano_id' => ['nullable', 'exists:canos_estandar,id'],
            'lineas.*.calc_medidas' => ['nullable', 'array'],
            'lineas.*.calc_medidas.*.valor' => ['nullable', 'numeric'],
            'lineas.*.calc_medidas.*.unidad' => ['nullable', 'string', 'in:mm,cm,m,in,ft'],

            // Alternativas: el mismo item cotizado de otra manera (aereo o
            // maritimo, por tramos de cantidad, con otro material).
            'lineas.*.opciones' => ['nullable', 'array', 'max:10'],
            'lineas.*.opciones.*.etiqueta' => ['required', 'string', 'max:60'],
            'lineas.*.opciones.*.tipo' => ['nullable', Rule::in(ConsultaLineaOpcion::TIPOS)],
            'lineas.*.opciones.*.cantidad' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.opciones.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.opciones.*.precio_por_kilo' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.opciones.*.plazo_dias' => ['nullable', 'integer', 'min:0', 'max:999'],
            'lineas.*.opciones.*.material_id' => ['nullable', 'exists:materiales,id'],
            'lineas.*.opciones.*.descripcion' => ['nullable', 'string', 'max:255'],
            'lineas.*.opciones.*.nota' => ['nullable', 'string', 'max:200'],
            'lineas.*.opciones.*.es_base' => ['boolean'],
            'lineas.*.deposito' => ['nullable', 'string', 'max:40'],
            'lineas.*.colada' => ['nullable', 'string', 'max:40'],

            // Importacion o stock: cambian el plazo de entrega y la forma de
            // pago, asi que no hay uno por defecto.
            'juego_condiciones' => ['nullable', Rule::in(['Importacion', 'Stock'])],
            'condiciones' => ['array'],
            'condiciones.*.titulo' => ['nullable', 'string', 'max:60'],
            // Los bloques reales pasan los 700 caracteres: "Forma de Pago"
            // tiene 734 y "Validez de Oferta" 674. El limite viejo de 200 los
            // dejaba afuera.
            'condiciones.*.texto' => ['required', 'string', 'max:5000'],
            'condiciones.*.origen' => ['nullable', Rule::in(['Manual', 'De la ficha', 'Automatica'])],
            // Las internas se ven en la pantalla pero no salen en la hoja.
            'condiciones.*.imprime' => ['boolean'],
        ], [
            'lineas.*.descripcion.required' => 'Cada linea necesita al menos la descripcion, que es lo que sale impreso.',
            'lineas.*.id.exists' => 'Una de las lineas no es de esta cotizacion.',
        ]);

        $this->sinIdsRepetidos($datos['lineas'] ?? []);

        return $datos;
    }

    /**
     * Dos lineas no pueden traer el mismo id.
     *
     * Si pasara, la segunda pisaria a la primera y la cotizacion terminaria
     * con una linea menos sin que nadie lo note.
     *
     * @throws ValidationException
     */
    private function sinIdsRepetidos(array $lineas): void
    {
        $ids = collect($lineas)->pluck('id')->filter()->values();

        if ($ids->count() === $ids->unique()->count()) {
            return;
        }

        throw ValidationException::withMessages([
            'lineas' => 'Llegaron dos lineas con el mismo numero. Volvé a abrir la cotizacion y guardala de nuevo.',
        ]);
    }

    private function soloCabecera(array $datos): array
    {
        return collect($datos)->except(['lineas', 'condiciones', 'validez_dias'])->all();
    }

    /**
     * Guarda las lineas emparejandolas con las que ya estaban.
     *
     * Antes se borraban todas y se creaban de nuevo. Eso perdia la procedencia
     * del factor en cada guardado: una linea calculada volvia a nacer sin
     * saber de donde venia su numero. Ahora la que trae id se actualiza, la
     * que no trae se crea, y la que dejo de venir se borra.
     */
    private function guardarLineas(Consulta $consulta, array $lineas): void
    {
        $existentes = $consulta->lineas()->get()->keyBy('id');
        $sobreviven = [];

        foreach ($lineas as $i => $datos) {
            $calculo = [
                'medidas' => $datos['calc_medidas'] ?? [],
                'piezas' => $datos['calc_piezas'] ?? null,
                'cano_id' => $datos['calc_cano_id'] ?? null,
            ];

            // La linea que ya existia se actualiza; la nueva se crea.
            $anterior = isset($datos['id']) ? $existentes->get($datos['id']) : null;
            $linea = $anterior ?? new ConsultaLinea;

            // Un material fuera del catalogo se da de alta antes de llenar la
            // linea: de ahi en adelante es un material como cualquier otro.
            $datos['material_id'] = $this->resolverElMaterial($datos);

            $linea->fill(
                collect($datos)
                    ->except(['id', 'calc_medidas', 'calc_piezas', 'calc_cano_id', 'opciones',
                        'aplicar_calculo_al_factor', 'material_nuevo',
                        // El navegador no decide de donde vino el factor.
                        'factor_calculado', 'origen_factor', 'factor_cargado_por', 'factor_cargado_el'])
                    ->all()
            );
            $linea->consulta_id = $consulta->id;
            $linea->orden = $i + 1;

            $this->calcularElPeso($linea, $calculo);

            // Cuando se cotiza tal cual lo pidieron, lo pedido se completa solo.
            if ($linea->igual_a_lo_pedido) {
                $linea->pedido_material = null;
                $linea->pedido_forma = null;
                $linea->pedido_dimensiones = null;
                $linea->motivo_cambio = null;
            }

            $this->resolverElFactor($linea, (bool) ($datos['aplicar_calculo_al_factor'] ?? false));

            // Primero se guarda la linea, despues sus alternativas, y recien
            // ahi se recalcula: el importe de la linea sale de la alternativa
            // base, asi que tienen que existir antes de sacar la cuenta.
            $linea->recalcular();
            $linea->save();

            $sobreviven[] = $linea->id;
            $linea->opciones()->delete();
            $this->guardarOpciones($linea, $datos['opciones'] ?? []);
        }

        // Las que dejaron de venir se sacaron de la cotizacion.
        $consulta->lineas()->whereNotIn('id', $sobreviven ?: [0])->delete();
    }

    /**
     * Las alternativas de la linea.
     *
     * Una sola puede ser la base. Si no marcaron ninguna, es la primera: algo
     * tiene que contar para el total, y no se puede adivinar.
     */
    private function guardarOpciones(ConsultaLinea $linea, array $opciones): void
    {
        if ($opciones === []) {
            return;
        }

        $yaHayBase = false;

        foreach (array_values($opciones) as $i => $datos) {
            $esBase = ($datos['es_base'] ?? false) && ! $yaHayBase;
            $yaHayBase = $yaHayBase || $esBase;

            $linea->opciones()->create([
                'orden' => $i + 1,
                'etiqueta' => $datos['etiqueta'],
                'tipo' => $datos['tipo'] ?? 'Otra',
                'cantidad' => $datos['cantidad'] ?? null,
                'precio_unitario' => $datos['precio_unitario'] ?? null,
                'precio_por_kilo' => $datos['precio_por_kilo'] ?? null,
                'plazo_dias' => $datos['plazo_dias'] ?? null,
                'material_id' => $datos['material_id'] ?? null,
                'descripcion' => $datos['descripcion'] ?? null,
                'nota' => $datos['nota'] ?? null,
                'es_base' => $esBase,
            ]);
        }

        $linea->load('opciones');

        if (! $yaHayBase) {
            $linea->opciones->first()->update(['es_base' => true]);
            $linea->load('opciones');
        }

        // Ahora que estan las alternativas, el importe de la linea sale de la base.
        $linea->recalcular();
        $linea->save();
    }

    /**
     * El material de la linea, incluso cuando todavia no esta en el catalogo.
     *
     * El catalogo del sistema anterior no esta cargado, asi que el cliente
     * pide cosas que este sistema no tiene: "Titanio Grado 7" no figura en la
     * lista y la linea quedaba sin material — y sin material no hay densidad,
     * no hay peso, no hay factor y no hay importe. Quien cotiza veia un
     * desplegable que no ofrecia lo que le habian pedido.
     *
     * Ahora el nombre escrito se da de alta ahi mismo, SIN densidad. Sin
     * densidad no se calcula el peso y la pantalla lo dice; pero la linea
     * queda con su material, sale impresa bien, y la proxima cotizacion ya lo
     * encuentra en la lista. La densidad la carga la empresa cuando tenga el
     * dato: el sistema no la inventa.
     *
     * Antes de dar de alta se busca, por nombre y por alias, comparando en
     * minuscula: "titanio gr2" encuentra "TITANIO GR2" y no queda el mismo
     * material dos veces. La comparacion se escribe con LOWER() a proposito y
     * no se deja librada a la collation, porque no es la misma en los dos
     * lados: MySQL ignora mayusculas y el SQLite de los tests no.
     */
    private function resolverElMaterial(array $datos): ?int
    {
        if (! empty($datos['material_id'])) {
            return (int) $datos['material_id'];
        }

        $nombre = trim((string) ($datos['material_nuevo'] ?? ''));

        if ($nombre === '') {
            return null;
        }

        $comoSeEscribe = mb_strtolower($nombre);

        $existente = Material::whereRaw('LOWER(nombre) = ?', [$comoSeEscribe])->first()
            ?? Material::whereHas(
                'alias',
                fn ($a) => $a->whereRaw('LOWER(alias) = ?', [$comoSeEscribe])
            )->first();

        if ($existente) {
            return $existente->id;
        }

        return Material::create(['nombre' => $nombre, 'densidad' => null, 'activo' => true])->id;
    }

    /**
     * El factor de la linea: calculado o cargado a mano, y quien lo cargo.
     *
     * Si no vino ninguno, se propone el que da la cuenta. Si vino uno, se
     * compara contra lo que daria la cuenta: si coincide es el calculado, y si
     * no, alguien lo corrigio a mano y queda registrado con nombre y fecha.
     *
     * La comparacion la hace el servidor a proposito. El navegador podria decir
     * "este es el calculado" y no serlo, y un peso a mano disfrazado de
     * calculado es justamente lo que nadie revisa.
     */
    private function resolverElFactor(ConsultaLinea $linea, bool $aplicarCalculo): void
    {
        if (! $linea->cambiaDeUnidad()) {
            return;
        }

        $escrito = $linea->factor_conversion;

        // Lo que estaba guardado antes de este pedido. Se lee de getOriginal()
        // y no del modelo: fill() ya piso los atributos con lo que llego, asi
        // que el objeto ya no sabe lo que tenia.
        $antes = $linea->exists ? $linea->getOriginal('factor_conversion') : null;

        // 1 · Pidieron aplicar el calculo. El servidor lo saca y lo aplica: lo
        //     que haya escrito el navegador no se mira.
        if ($aplicarCalculo) {
            $calculado = $this->factorDeLaCuenta($linea);

            $calculado === null
                ? $this->sinFactor($linea)
                : $this->factorDeLaCalculadora($linea, $calculado);

            return;
        }

        // 2 · No escribieron nada y no habia nada: se propone el de la cuenta.
        //     Lo genera y lo aplica el servidor, asi que es de la calculadora.
        if ($escrito === null && $antes === null) {
            $calculado = $this->factorDeLaCuenta($linea);

            $calculado === null
                ? $this->sinFactor($linea)
                : $this->factorDeLaCalculadora($linea, $calculado);

            return;
        }

        // 3 · Borraron el factor que habia.
        if ($escrito === null) {
            $this->sinFactor($linea);

            return;
        }

        // 4 · Vino el mismo valor que ya estaba guardado: no lo tocaron, asi
        //     que conserva su procedencia. Una linea calculada que se reabre y
        //     se vuelve a guardar sigue siendo calculada.
        if ($antes !== null && (float) $antes === (float) $escrito) {
            foreach (['factor_calculado', 'origen_factor', 'factor_cargado_por', 'factor_cargado_el'] as $campo) {
                $linea->setAttribute($campo, $linea->getOriginal($campo));
            }

            return;
        }

        // 5 · Lo escribio o lo modifico una persona. Aunque el numero coincida
        //     con el que da la formula: que coincida no prueba de donde vino, y
        //     un factor escrito a mano es el que hay que poder revisar.
        $this->factorAMano($linea);
    }

    /**
     * Los kilos de UNA unidad de venta.
     *
     * Si se vende por metro son los kilos de un metro. Si se vende por unidad
     * son los de esa pieza, con su largo real: una barra Ø127 de titanio pesa
     * 57,13 kg el metro y 1,451 kg si la pieza mide 25,4 mm.
     */
    private function factorDeLaCuenta(ConsultaLinea $linea): ?float
    {
        $porMetro = $linea->unidadVenta?->codigo === 'MT';

        return app(CalculadoraFactor::class)->calcular(
            $linea->material_id ? Material::find($linea->material_id) : null,
            $linea->forma_id ? Forma::find($linea->forma_id) : null,
            $linea->diametro_mm ? (float) $linea->diametro_mm : null,
            $linea->espesor_mm ? (float) $linea->espesor_mm : null,
            $linea->ancho_mm ? (float) $linea->ancho_mm : null,
            largoMm: $porMetro ? null : (float) ($linea->largo_mm ?? 0),
        )['factor'];
    }

    /** Lo saco el servidor: sin responsable, porque no lo puso una persona. */
    private function factorDeLaCalculadora(ConsultaLinea $linea, float $factor): void
    {
        $linea->factor_conversion = $factor;
        $linea->factor_calculado = true;
        $linea->origen_factor = ConsultaLinea::FACTOR_CALCULADORA;
        $linea->factor_cargado_por = null;
        $linea->factor_cargado_el = null;
    }

    /** Lo puso una persona: queda con nombre y fecha. */
    private function factorAMano(ConsultaLinea $linea): void
    {
        $linea->factor_calculado = false;
        $linea->origen_factor = ConsultaLinea::FACTOR_MANUAL;
        $linea->factor_cargado_por = request()->user()?->id;
        $linea->factor_cargado_el = now();
    }

    /** La forma no tiene formula o faltan medidas: no se inventa un numero. */
    private function sinFactor(ConsultaLinea $linea): void
    {
        $linea->factor_conversion = null;
        $linea->factor_calculado = false;
        $linea->origen_factor = null;
        $linea->factor_cargado_por = null;
        $linea->factor_cargado_el = null;
    }

    /**
     * Rehace el peso de la linea y le deja guardada la foto del calculo.
     *
     * El navegador manda las medidas, no el resultado: el peso lo saca el
     * servidor. Y queda escrito con que densidad y con que formula se saco, de
     * manera que corregir una densidad mañana no cambie lo que se cotizo hoy.
     */
    private function calcularElPeso(ConsultaLinea $linea, array $calculo): void
    {
        if (! $linea->forma_id || ! $linea->material_id || empty($calculo['medidas'])) {
            return;
        }

        $piezas = (float) ($calculo['piezas'] ?? $linea->cantidad ?? 1);

        $resultado = app(CalculadoraDePeso::class)->calcular(
            Material::find($linea->material_id),
            Forma::find($linea->forma_id),
            $calculo['medidas'],
            piezas: $piezas > 0 ? $piezas : 1,
            cano: $calculo['cano_id'] ? CanoEstandar::find($calculo['cano_id']) : null,
        );

        $linea->calculo = $resultado;
        // La columna guarda el peso de toda la linea; el unitario queda en la foto.
        $linea->peso_kg = $resultado['ok'] ? $resultado['peso_total_kg'] : null;

        // Las medidas sueltas de la linea se completan con las de la
        // calculadora, para que el resto del sistema las siga encontrando.
        $linea->diametro_mm ??= data_get($resultado, 'medidas.diameter.valor_mm')
            ?? data_get($resultado, 'medidas.outer.valor_mm');
        $linea->espesor_mm ??= data_get($resultado, 'medidas.wall.valor_mm')
            ?? data_get($resultado, 'medidas.height.valor_mm');
        $linea->ancho_mm ??= data_get($resultado, 'medidas.width.valor_mm');
        $linea->largo_mm ??= data_get($resultado, 'medidas.length.valor_mm');
    }

    private function guardarCondiciones(Consulta $consulta, array $condiciones): void
    {
        foreach ($condiciones as $i => $datos) {
            ConsultaCondicion::create([
                'consulta_id' => $consulta->id,
                'orden' => $i + 1,
                'titulo' => $datos['titulo'] ?? null,
                // El {vence} lo resuelve el servidor SIEMPRE, venga de donde
                // venga: es el unico que sabe hasta cuando vale esta oferta.
                // Si lo resolviera el navegador, cambiar la validez despues de
                // haber puesto las condiciones dejaria la fecha vieja escrita.
                'texto' => CondicionHabitual::conFecha($datos['texto'], $consulta),
                'imprime' => $datos['imprime'] ?? true,
                'origen' => $datos['origen'] ?? 'Manual',
            ]);
        }

        // La linea suelta de validez solo tiene sentido si no vino el bloque
        // que ya la incluye: si no, la hoja diria dos veces hasta cuando vale
        // la oferta, y son dos textos que se pueden contradecir.
        if ($this->hayBloqueDeValidez($condiciones)) {
            return;
        }

        ConsultaCondicion::create([
            'consulta_id' => $consulta->id,
            'orden' => count($condiciones) + 1,
            'texto' => "Validez de la oferta: {$consulta->validez_dias} dias",
            'origen' => 'Automatica',
        ]);
    }

    private function hayBloqueDeValidez(array $condiciones): bool
    {
        foreach ($condiciones as $datos) {
            if (str_contains(mb_strtolower($datos['titulo'] ?? ''), 'validez')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Con que condiciones arranca una cotizacion nueva.
     *
     * Las del juego que se eligio —importacion o stock—, que son mas de 2000
     * caracteres de terminos que la empresa repite en cada oferta y que antes
     * habia que tipear o pegar a mano. Si no eligieron juego no entra ninguna:
     * los dos difieren en plazo y forma de pago.
     *
     * Se copia el texto, no una referencia: cambiar la plantilla manana no
     * puede cambiar lo que decia una cotizacion que ya se mando.
     */
    private function condicionesPorDefecto(Consulta $consulta): array
    {
        return CondicionHabitual::delJuego($consulta->juego_condiciones)
            ->map(fn (CondicionHabitual $c) => [
                'titulo' => $c->titulo,
                'texto' => $c->texto,
                'origen' => 'Automatica',
            ])
            ->all();
    }

    private function recargar(Consulta $consulta): Consulta
    {
        return $consulta->fresh([
            'empresa', 'contacto', 'razonSocial', 'usuario', 'moneda',
            'lineas.material', 'lineas.forma', 'lineas.unidadVenta', 'lineas.opciones.material',
            'lineas.unidadFactura', 'lineas.unidadPedida',
            'condiciones', 'observaciones.usuario', 'impresiones.contacto',
            'impresiones.usuario', 'copiadaDe.empresa',
        ]);
    }
}
