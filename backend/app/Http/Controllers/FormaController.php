<?php

namespace App\Http\Controllers;

use App\Models\Forma;
use App\Models\Material;
use App\Services\CalculadoraDePeso;
use App\Services\EvaluadorDeFormulas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administracion de formas y sus cuentas de peso.
 *
 * Cambiar una formula acá cambia lo que se va a calcular de ahora en adelante.
 * Lo ya cotizado NO se toca: cada linea guardo su propia foto el dia que se
 * hizo, y esa foto es la que vale.
 */
class FormaController extends Controller
{
    /** Las medidas que puede pedir una forma, con el nombre que usa la formula. */
    public const MEDIDAS = [
        'diameter' => 'Diametro',
        'outer' => 'Diametro exterior',
        'inner' => 'Diametro interior',
        'wall' => 'Espesor de pared',
        'height' => 'Espesor',
        'width' => 'Ancho',
        'length' => 'Largo',
        'side' => 'Lado',
        'across' => 'Distancia entre caras',
    ];

    public function __construct(private EvaluadorDeFormulas $evaluador) {}

    public function index()
    {
        $formas = Forma::orderBy('orden')->orderBy('nombre')->get();

        // Cuantas lineas ya cotizadas usan cada forma. No se van a recalcular,
        // pero quien edita tiene que saber de que tamaño es lo que toca.
        $lineas = DB::table('consulta_lineas')
            ->select('forma_id', DB::raw('count(*) as cuantas'))
            ->whereNotNull('forma_id')
            ->groupBy('forma_id')
            ->pluck('cuantas', 'forma_id');

        $materiales = Material::whereNotNull('forma_habitual_id')
            ->select('forma_habitual_id', DB::raw('count(*) as cuantos'))
            ->groupBy('forma_habitual_id')
            ->pluck('cuantos', 'forma_habitual_id');

        return [
            'medidas_posibles' => collect(self::MEDIDAS)
                ->map(fn ($label, $clave) => ['clave' => $clave, 'label' => $label])
                ->values(),
            'unidades' => array_keys(CalculadoraDePeso::A_MILIMETROS),
            'formas' => $formas->map(fn (Forma $f) => [
                'id' => $f->id,
                'clave' => $f->clave,
                'nombre' => $f->nombre,
                'campos' => $f->camposDelCalculo(),
                'expresion' => $f->expresion,
                'usa_cano' => (bool) $f->usa_cano,
                'activo' => (bool) $f->activo,
                'orden' => $f->orden,
                'medidas_habituales' => $f->medidas_habituales,
                'medidas_necesarias' => $f->medidas_necesarias,
                // El impacto de tocarla.
                'lineas_cotizadas' => (int) ($lineas[$f->id] ?? 0),
                'materiales_que_la_usan' => (int) ($materiales[$f->id] ?? 0),
                // Con historial la clave queda congelada: renombrarla es una
                // migracion, no una edicion de pantalla.
                'clave_editable' => ($lineas[$f->id] ?? 0) === 0 && ($materiales[$f->id] ?? 0) === 0,
                // Como esta la cuenta de esta forma, de un vistazo.
                ...$this->estadoDeLaFormula($f),
            ]),
        ];
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);

        $datos['clave'] ??= Forma::claveDesde($datos['nombre']);
        $datos['orden'] ??= (int) Forma::max('orden') + 1;

        return response()->json(Forma::create($datos), 201);
    }

    public function update(Request $request, Forma $forma)
    {
        $forma->update($this->validar($request, $forma));

        return $forma->fresh();
    }

    /**
     * Borrar una forma.
     *
     * Solo si nunca se uso. Si ya hay cotizaciones con ella, borrarla dejaria
     * esas lineas apuntando a nada: se desactiva, que la saca de la lista al
     * cotizar pero deja intacto lo que ya se imprimio y se mando.
     */
    /**
     * En que estado esta la cuenta de una forma.
     *
     *  · valida      — se resuelve y todas sus medidas estan declaradas
     *  · sin_formula — no tiene cuenta: el peso se carga a mano
     *  · invalida    — tiene cuenta pero no se puede resolver
     *
     * "invalida" no deberia pasar, porque al guardar se valida. Igual se
     * revisa: una formula puede quedar rota por una migracion, una importacion
     * o alguien tocando la base. Y una formula rota que nadie ve devuelve
     * pesos equivocados sin avisar.
     *
     * @return array{estado_formula: string, problema_formula: ?string}
     */
    private function estadoDeLaFormula(Forma $forma): array
    {
        if (! filled($forma->expresion)) {
            return ['estado_formula' => 'sin_formula', 'problema_formula' => null];
        }

        $declaradas = collect($forma->camposDelCalculo())->pluck('clave')->all();

        if ($forma->usa_cano) {
            // El caño trae el diametro y la pared de la tabla, no de un campo.
            $declaradas = array_merge($declaradas, ['outer', 'wall']);
        }

        try {
            $usadas = $this->evaluador->variables($forma->expresion);
            $this->evaluador->evaluar($forma->expresion, array_fill_keys($usadas, 1.0));
        } catch (\InvalidArgumentException $e) {
            return ['estado_formula' => 'invalida', 'problema_formula' => $e->getMessage()];
        }

        $sinDeclarar = array_diff($usadas, $declaradas);

        if ($sinDeclarar !== []) {
            return [
                'estado_formula' => 'invalida',
                'problema_formula' => 'Usa '.implode(' y ', $sinDeclarar)
                    .', que no esta entre las medidas de la forma.',
            ];
        }

        return ['estado_formula' => 'valida', 'problema_formula' => null];
    }

    /** Cuanto se uso una forma: es lo que decide si se puede tocar o borrar. */
    private function cuantoSeUso(Forma $forma): array
    {
        return [
            'lineas' => DB::table('consulta_lineas')->where('forma_id', $forma->id)->count(),
            'materiales' => Material::where('forma_habitual_id', $forma->id)->count(),
        ];
    }

    private function enCriollo(array $usos): string
    {
        $partes = [];

        if ($usos['lineas'] > 0) {
            $partes[] = $usos['lineas'].($usos['lineas'] === 1 ? ' cotizacion' : ' cotizaciones');
        }

        if ($usos['materiales'] > 0) {
            $partes[] = $usos['materiales'].($usos['materiales'] === 1 ? ' material' : ' materiales');
        }

        return implode(' y ', $partes);
    }

    public function destroy(Forma $forma)
    {
        ['lineas' => $lineas, 'materiales' => $materiales] = $this->cuantoSeUso($forma);

        if ($lineas > 0 || $materiales > 0) {
            $forma->update(['activo' => false]);

            return response()->json([
                'borrada' => false,
                'mensaje' => $this->porQueNoSeBorra($lineas, $materiales),
                'forma' => $forma->fresh(),
            ], 200);
        }

        $forma->delete();

        return response()->json(['borrada' => true, 'mensaje' => 'Forma borrada.']);
    }

    private function porQueNoSeBorra(int $lineas, int $materiales): string
    {
        $partes = [];

        if ($lineas > 0) {
            $partes[] = $lineas.($lineas === 1 ? ' cotizacion la usa' : ' cotizaciones la usan');
        }

        if ($materiales > 0) {
            $partes[] = $materiales.($materiales === 1 ? ' material la tiene' : ' materiales la tienen')
                .' como forma habitual';
        }

        return 'No se borro porque '.implode(' y ', $partes)
            .'. Quedo desactivada: no aparece mas al cotizar, y las cotizaciones viejas '
            .'siguen mostrando lo que dijeron el dia que se hicieron.';
    }


    /** El orden en que se muestran: primero las que mas se usan. */
    public function ordenar(Request $request)
    {
        $datos = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:formas,id'],
        ]);

        foreach ($datos['ids'] as $i => $id) {
            Forma::where('id', $id)->update(['orden' => $i + 1]);
        }

        return response()->noContent();
    }

    /**
     * Prueba una formula sin guardarla.
     *
     * Devuelve la formula ya normalizada, que medidas nombra, si alguna no esta
     * declarada, y cuanto da con los valores de ejemplo. Es lo que permite ver
     * si la cuenta hace lo que uno cree ANTES de dejarla andando.
     */
    public function probar(Request $request)
    {
        $datos = $request->validate([
            'expresion' => ['required', 'string', 'max:2000'],
            'campos' => ['array'],
            'campos.*.clave' => ['required', 'string'],
            'valores' => ['array'],
        ]);

        $formula = $this->evaluador->desdeExcel($datos['expresion']);
        $declaradas = collect($datos['campos'] ?? [])->pluck('clave')->all();

        try {
            $usadas = $this->evaluador->variables($formula);
        } catch (\InvalidArgumentException $e) {
            return ['formula' => $formula, 'ok' => false, 'error' => $e->getMessage()];
        }

        $sinDeclarar = array_values(array_diff($usadas, $declaradas));

        // Valores de ejemplo: lo que mandaron, y 1 para lo que falte.
        $valores = [];

        foreach ($usadas as $nombre) {
            $valores[$nombre] = (float) ($datos['valores'][$nombre] ?? 1);
        }

        $resultado = null;
        $error = null;

        try {
            $resultado = round($this->evaluador->evaluar($formula, $valores), 4);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        return [
            'formula' => $formula,
            'ok' => $error === null && $sinDeclarar === [],
            'error' => $error,
            'variables' => $usadas,
            'sin_declarar' => $sinDeclarar,
            'valores_usados' => $valores,
            'volumen_cm3' => $resultado,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function validar(Request $request, ?Forma $forma = null): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:60'],
            // La clave es el nombre corto y estable: se puede corregir el
            // nombre visible sin romper lo que apunte a la forma.
            'clave' => [
                'nullable', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('formas', 'clave')->ignore($forma?->id),
            ],
            'orden' => ['nullable', 'integer', 'min:0', 'max:999'],
            'campos' => ['array'],
            'campos.*.clave' => ['required', 'string', 'in:'.implode(',', array_keys(self::MEDIDAS))],
            'campos.*.label' => ['nullable', 'string', 'max:40'],
            'expresion' => ['nullable', 'string', 'max:2000'],
            'usa_cano' => ['boolean'],
            'activo' => ['boolean'],
            'medidas_habituales' => ['nullable', 'string', 'max:120'],
            'medidas_necesarias' => ['nullable', 'string', 'max:120'],
        ]);

        // La clave de una forma con historial no se toca. Es lo que identifica
        // a la forma; cambiarla dejaria las cotizaciones viejas apuntando a un
        // nombre que ya no existe. Si hace falta renombrarla, va por migracion,
        // que puede acomodar todo lo que dependa de ella en la misma operacion.
        if ($forma && filled($datos['clave'] ?? null) && $datos['clave'] !== $forma->clave) {
            $usos = $this->cuantoSeUso($forma);

            if ($usos['lineas'] > 0 || $usos['materiales'] > 0) {
                throw ValidationException::withMessages([
                    'clave' => 'La clave "'.$forma->clave.'" no se puede cambiar porque la forma '
                        .'ya tiene historial ('.$this->enCriollo($usos).'). Si hay que renombrarla, '
                        .'se hace por migracion.',
                ]);
            }
        }

        $repetido = Forma::where('nombre', $datos['nombre'])
            ->when($forma, fn ($q) => $q->where('id', '!=', $forma->id))
            ->exists();

        if ($repetido) {
            throw ValidationException::withMessages([
                'nombre' => 'Ya hay una forma que se llama asi.',
            ]);
        }

        $datos['campos'] = collect($datos['campos'] ?? [])
            ->map(fn ($c) => [
                'clave' => $c['clave'],
                'label' => $c['label'] ?: self::MEDIDAS[$c['clave']],
            ])
            ->values()
            ->all();

        if (filled($datos['expresion'] ?? null)) {
            $datos['expresion'] = $this->revisarLaFormula($datos);
        } else {
            // Sin formula la forma existe igual: se carga el peso a mano.
            $datos['expresion'] = null;
        }

        return $datos;
    }

    /** @throws ValidationException */
    private function revisarLaFormula(array $datos): string
    {
        $formula = $this->evaluador->desdeExcel($datos['expresion']);
        $declaradas = collect($datos['campos'])->pluck('clave')->all();

        // El caño trae el diametro y la pared de la tabla, no de un campo.
        if ($datos['usa_cano'] ?? false) {
            $declaradas = array_merge($declaradas, ['outer', 'wall']);
        }

        try {
            $usadas = $this->evaluador->variables($formula);
            $this->evaluador->evaluar($formula, array_fill_keys($usadas, 1.0));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['expresion' => $e->getMessage()]);
        }

        $sinDeclarar = array_diff($usadas, $declaradas);

        if ($sinDeclarar !== []) {
            // Sin esto la formula tomaria cero para esa medida y devolveria un
            // peso mal calculado sin avisar.
            throw ValidationException::withMessages([
                'expresion' => 'La formula usa '.implode(' y ', $sinDeclarar)
                    .', que no está en las medidas de la forma. Agregá la medida o corregí la formula.',
            ]);
        }

        return $formula;
    }
}
