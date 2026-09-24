<?php

namespace App\Services;

use App\Models\ConsultaLineaOpcion;
use App\Models\Forma;
use App\Models\Material;
use App\Models\Unidad;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee el pedido del cliente con ayuda de la IA.
 *
 * La IA prepara, la persona confirma: devuelve líneas propuestas que después
 * se revisan y se corrigen en pantalla. Nunca guarda nada por su cuenta.
 *
 * Si no hay credencial cargada, o si el servicio falla, el sistema sigue
 * andando con el lector de reglas. La IA es una ayuda, no un requisito.
 */
class InterpreteIA
{
    public function __construct(private LectorDeSolicitud $lectorDeReglas) {}

    public function estaConfigurada(): bool
    {
        return filled(config('services.openai.key'));
    }

    /**
     * @return array{lineas: array, sin_reconocer: array, con_ia: bool, aviso: ?string}
     */
    public function interpretar(string $texto): array
    {
        if (! $this->estaConfigurada()) {
            return $this->conReglas($texto, 'La IA no esta configurada: se leyo con las reglas de siempre.');
        }

        try {
            $lineas = $this->preguntarALaIA($texto);

            if ($lineas === null) {
                return $this->conReglas($texto, 'La IA contesto algo que no se entiende: se leyo con las reglas de siempre.');
            }

            return [
                'lineas' => $lineas,
                'sin_reconocer' => [],
                'con_ia' => true,
                'aviso' => null,
            ];
        } catch (\Throwable $e) {
            // Que falle la IA no puede dejar sin cargar la cotización. Y el
            // aviso tiene que decir por qué fallo: si no, nadie sabe si hay que
            // cargar credito, cambiar la credencial o simplemente esperar.
            Log::warning('No se pudo leer con IA: '.$e->getMessage());

            return $this->conReglas($texto, $e->getMessage().': se leyo con las reglas de siempre.');
        }
    }

    private function conReglas(string $texto, string $aviso): array
    {
        $resultado = $this->lectorDeReglas->interpretar($texto);

        return [
            'lineas' => $resultado['lineas'],
            'sin_reconocer' => $resultado['sin_reconocer'],
            'con_ia' => false,
            'aviso' => $aviso,
        ];
    }

    private function preguntarALaIA(string $texto): ?array
    {
        $materiales = Material::where('activo', true)->pluck('nombre')->all();
        $formas = Forma::where('activo', true)->pluck('nombre')->all();
        $unidades = Unidad::where('activo', true)->pluck('codigo')->all();

        $respuesta = Http::withToken(config('services.openai.key'))
            ->timeout(config('services.openai.timeout', 30))
            ->post(rtrim(config('services.openai.url'), '/').'/chat/completions', [
                'model' => config('services.openai.model'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->instrucciones($materiales, $formas, $unidades)],
                    ['role' => 'user', 'content' => $texto],
                ],
            ]);

        if ($respuesta->failed()) {
            Log::warning('OpenAI respondio '.$respuesta->status().': '.$respuesta->body());

            throw new \RuntimeException($this->motivo($respuesta->status()));
        }

        $contenido = data_get($respuesta->json(), 'choices.0.message.content');
        $datos = json_decode((string) $contenido, true);

        if (! is_array($datos) || ! isset($datos['lineas'])) {
            return null;
        }

        return $this->aLineasDelSistema($datos['lineas']);
    }

    /**
     * Por que fallo, en criollo y accionable.
     *
     * Quien esta cargando la cotizacion no puede hacer nada con un numero de
     * error, pero si con "hay que cargar credito".
     */
    private function motivo(int $estado): string
    {
        return match (true) {
            $estado === 401, $estado === 403 => 'La credencial de la IA no sirve o vencio',
            $estado === 429 => 'La cuenta de la IA no tiene credito o llego al limite de consultas',
            $estado >= 500 => 'El servicio de IA no esta respondiendo',
            default => 'La IA no pudo leerlo',
        };
    }

    private function instrucciones(array $materiales, array $formas, array $unidades): string
    {
        return <<<TXT
        Sos parte del sistema de Roberto Cordes S.A., que vende materiales especiales
        (aleaciones de niquel, titanio, inoxidables) a la industria argentina.

        Te va a llegar el texto de un pedido de cotizacion, tal como lo mando el cliente
        por mail o por WhatsApp. Tu tarea es separarlo en lineas de cotizacion.

        Devolve SOLO un JSON con esta forma:
        {"lineas": [{"descripcion": "...", "pedido_material": "...", "material": "...",
                     "forma": "...", "dimensiones": "...", "diametro_mm": 0,
                     "espesor_mm": 0, "ancho_mm": 0, "largo_mm": 0, "cantidad": 0, "unidad": "...",
                     "alternativas": [{"etiqueta": "...", "tipo": "...", "cantidad": 0}]}]}

        Reglas:
        - "descripcion" es el renglon original, tal cual lo escribio el cliente.
        - "pedido_material" es el material como lo escribio el cliente, sin tocarlo.
        - "material" tiene que ser EXACTAMENTE uno de esta lista, o null:
          {$this->lista($materiales)}

        REGLA MAS IMPORTANTE — no sustituyas materiales:
        Si el cliente pide una variante que NO esta en la lista, poné material null.
        NUNCA la cambies por la que mas se le parece. Una letra cambia la aleacion:
        316L no es 316, 304L no es 304, GR5 no es GR2. Si la variante exacta no
        figura, va null y lo resuelve una persona. Cotizar la aleacion equivocada
        es peor que no cotizar.

        - "forma" tiene que ser EXACTAMENTE una de esta lista, o null:
          {$this->lista($formas)}
        - "unidad" tiene que ser EXACTAMENTE una de: {$this->lista($unidades)}
        - "dimensiones" son SOLO las medidas, no la frase entera: de "10 metros de
          barra de titanio de 25.4 mm" corresponde "25.4 MM", no todo el renglon.
        - Las medidas van en milimetros. Si el cliente escribe pulgadas, dejá
          "dimensiones" con el texto original y no completes los milimetros.
        - "largo_mm" es el largo de la pieza: de "Ø 260 x 110mm de largo" son 110.
          Sin el largo no se puede calcular el peso de una barra, asi que si el
          cliente lo escribio, tiene que estar.
        - Si un dato no esta, poné null. NO INVENTES medidas, cantidades ni precios.
        - Devolve UNA linea por cada renglon pedido, ni una mas. Si dos renglones
          son iguales, son dos lineas solo si el cliente los escribio dos veces.
        - Ignorá saludos, firmas, encabezados de mail y comentarios que no sean pedidos.
        - Si el texto no tiene ningun pedido de material, devolvé {"lineas": []}.

        ALTERNATIVAS — cuando el cliente pide el mismo item de varias maneras:
        Es muy comun que pidan cotizar dos o tres variantes del MISMO material.
        Si lo ves, poné cada variante en "alternativas". Si no, dejá la lista vacia.

        - "cotizar aerea y maritima", "por avion y por barco", "las dos vias":
          alternativas [{"etiqueta":"Maritimo","tipo":"Transporte"},
                        {"etiqueta":"Aereo","tipo":"Transporte"}]
        - "cotizar por 100, 500 y 1000 kg", "precio por 36,6 / 73,2 / 109,8 m":
          una alternativa por cada cantidad, tipo "Cantidad", con su "cantidad"
          y de etiqueta la cantidad con su unidad: "500 kg", "73,2 m".
        - Si ofrecen o piden comparar dos materiales para lo mismo:
          tipo "Material" y de etiqueta el nombre del material.

        NO pongas precios en las alternativas: el cliente pide, no cotiza. Los
        precios los carga despues una persona.
        Una linea sin variantes lleva "alternativas": [].
        TXT;
    }

    private function lista(array $valores): string
    {
        return implode(', ', $valores);
    }

    /** Pasa los nombres que devolvió la IA a los ids del sistema. */
    private function aLineasDelSistema(array $crudas): array
    {
        $materiales = Material::where('activo', true)->get();
        $formas = Forma::where('activo', true)->get();
        $unidades = Unidad::where('activo', true)->get();

        $lineas = [];

        foreach ($crudas as $cruda) {
            $descripcion = trim((string) data_get($cruda, 'descripcion', ''));

            if ($descripcion === '') {
                continue;
            }

            $material = $materiales->firstWhere('nombre', data_get($cruda, 'material'));
            $forma = $formas->firstWhere('nombre', data_get($cruda, 'forma'));
            $unidad = $unidades->firstWhere('codigo', data_get($cruda, 'unidad'));

            $lineas[] = [
                'descripcion' => $descripcion,
                // Lo que pidio el cliente queda escrito aparte de lo que se
                // cotiza: es la unica forma de ver despues si coincidian.
                'pedido_material' => data_get($cruda, 'pedido_material') ?: null,
                'material_id' => $material?->id,
                'material' => $material?->nombre,
                'forma_id' => $forma?->id,
                'forma' => $forma?->nombre,
                'dimensiones' => data_get($cruda, 'dimensiones') ?: null,
                'diametro_mm' => $this->numero(data_get($cruda, 'diametro_mm')),
                'espesor_mm' => $this->numero(data_get($cruda, 'espesor_mm')),
                'ancho_mm' => $this->numero(data_get($cruda, 'ancho_mm')),
                'largo_mm' => $this->numero(data_get($cruda, 'largo_mm')),
                'cantidad' => $this->numero(data_get($cruda, 'cantidad')),
                'unidad_venta_id' => $unidad?->id,
                'unidad' => $unidad?->codigo,
                // Si el material no calzo con ninguno del catalogo, la linea no
                // se puede dar por igual a lo pedido: la tilde queda sin marcar
                // para que alguien la mire antes de mandar la cotizacion.
                'igual_a_lo_pedido' => $material !== null,
                // Las variantes que pidio el cliente, ya armadas. Vienen sin
                // precio: el cliente pide, no cotiza.
                'alternativas' => $this->alternativasDe($cruda, $materiales),
            ];
        }

        return $lineas;
    }

    /**
     * Las variantes que pidió el cliente, listas para cargar.
     *
     * La primera queda como base porque algo tiene que contar para el total.
     * Van sin precio a propósito: el cliente pide, la persona cotiza.
     *
     * @param  \Illuminate\Support\Collection<int, Material>  $materiales
     */
    private function alternativasDe(mixed $cruda, $materiales): array
    {
        $tiposValidos = ConsultaLineaOpcion::TIPOS;
        $alternativas = [];

        foreach ((array) data_get($cruda, 'alternativas', []) as $i => $a) {
            $etiqueta = trim((string) data_get($a, 'etiqueta', ''));

            if ($etiqueta === '') {
                continue;
            }

            $tipo = data_get($a, 'tipo');
            $material = $materiales->firstWhere('nombre', data_get($a, 'material'));

            $alternativas[] = [
                'etiqueta' => mb_substr($etiqueta, 0, 60),
                'tipo' => in_array($tipo, $tiposValidos, true) ? $tipo : 'Otra',
                'cantidad' => $this->numero(data_get($a, 'cantidad')),
                'precio_unitario' => null,
                'plazo_dias' => null,
                'material_id' => $material?->id,
                'es_base' => $i === 0,
            ];
        }

        // Una sola variante no es una alternativa: es la linea.
        return count($alternativas) > 1 ? $alternativas : [];
    }

    private function numero(mixed $valor): ?float
    {
        if ($valor === null || $valor === '' || $valor === 0) {
            return null;
        }

        return is_numeric($valor) ? (float) $valor : null;
    }
}
