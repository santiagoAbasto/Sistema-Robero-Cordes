<?php

namespace App\Http\Controllers;

use App\Models\EmpresaCampo;
use App\Models\OpcionDeCondicion;
use App\Models\TipoDeCondicion;
use App\Services\CatalogoDeCondiciones;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Configuración > Condiciones de la ficha: la lista de condiciones de trabajo y los datos de cada una.
 *
 * Verla puede cualquiera; cambiarla, sólo un administrador: de esta lista salen todas las fichas.
 * El trabajo de fondo (renombrar, unir, propagar a las fichas) está en CatalogoDeCondiciones.
 */
class TiposDeCondicionController extends Controller
{
    public function __construct(private readonly CatalogoDeCondiciones $catalogo) {}

    public function index()
    {
        $tipos = TipoDeCondicion::query()->orderBy('orden')->orderBy('nombre')
            ->with(['opciones' => fn ($q) => $q->withCount('campos')])->withCount('campos')->get();

        return [
            'tipos' => $tipos->map(fn (TipoDeCondicion $t) => [
                'id' => $t->id,
                'nombre' => $t->nombre,
                'activo' => $t->activo,
                'proponer_al_cotizar' => $t->proponer_al_cotizar,
                'fichas' => $t->campos_count,
                'opciones' => $t->opciones->map(fn (OpcionDeCondicion $o) => [
                    'id' => $o->id, 'texto' => $o->texto, 'activo' => $o->activo, 'fichas' => $o->campos_count,
                ])->values(),
            ])->values(),
            // Lo que está cargado en fichas pero no en la lista: para revisarlo y sumarlo.
            'sueltos' => [
                'fichas' => EmpresaCampo::whereNull('opcion_de_condicion_id')->whereNotNull('valor')->where('valor', '!=', '')->count(),
                'datos' => $this->catalogo->sueltos(100),
            ],
        ];
    }

    public function store(Request $request)
    {
        $this->soloAdministradores($request);
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:60'], 'proponer_al_cotizar' => ['boolean']]);

        $tipo = $this->catalogo->tipo($datos['nombre']);
        isset($datos['proponer_al_cotizar']) && $tipo->update(['proponer_al_cotizar' => $datos['proponer_al_cotizar']]);

        return response()->json($tipo->fresh(), 201);
    }

    public function update(Request $request, TipoDeCondicion $tipo)
    {
        $this->soloAdministradores($request);
        $datos = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:60'],
            'activo' => ['sometimes', 'boolean'],
            'proponer_al_cotizar' => ['sometimes', 'boolean'],
        ]);

        if (isset($datos['nombre'])) {
            $this->catalogo->renombrarTipo($tipo, $datos['nombre']);
            unset($datos['nombre']);
        }

        $tipo->update($datos);

        return $tipo->fresh();
    }

    /** El orden en que se ofrecen al cargar una ficha. */
    public function ordenar(Request $request)
    {
        $this->soloAdministradores($request);
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        foreach (array_values($ids) as $i => $id) {
            TipoDeCondicion::whereKey($id)->update(['orden' => $i + 1]);
        }

        return response()->noContent();
    }

    public function unir(Request $request, TipoDeCondicion $tipo)
    {
        $this->soloAdministradores($request);
        $con = TipoDeCondicion::findOrFail($request->validate(['con' => ['required', 'integer']])['con']);

        return ['fichas' => $this->catalogo->unirTipos($tipo, $con), 'mensaje' => "Quedó todo en «{$con->nombre}»."];
    }

    /** Una condición que ya está en fichas no se borra: se desactiva (o se une con otra). */
    public function destroy(Request $request, TipoDeCondicion $tipo)
    {
        $this->soloAdministradores($request);
        $usos = $tipo->campos()->count();

        if ($usos > 0) {
            throw ValidationException::withMessages(['tipo' => "La usan {$usos} fichas: desactivala, o unila con otra."]);
        }

        $tipo->delete();

        return response()->noContent();
    }

    /* ------------------------------------------------------------------ datos */

    public function storeOpcion(Request $request, TipoDeCondicion $tipo)
    {
        $this->soloAdministradores($request);
        $texto = $request->validate(['texto' => ['required', 'string', 'max:255']])['texto'];

        return response()->json($this->catalogo->opcion($tipo, $texto), 201);
    }

    public function updateOpcion(Request $request, OpcionDeCondicion $opcion)
    {
        $this->soloAdministradores($request);
        $datos = $request->validate(['texto' => ['sometimes', 'required', 'string', 'max:255'], 'activo' => ['sometimes', 'boolean']]);

        if (isset($datos['texto'])) {
            $this->catalogo->renombrarOpcion($opcion, $datos['texto']);
            unset($datos['texto']);
        }

        $opcion->update($datos);

        return $opcion->fresh();
    }

    public function unirOpcion(Request $request, OpcionDeCondicion $opcion)
    {
        $this->soloAdministradores($request);
        $con = OpcionDeCondicion::findOrFail($request->validate(['con' => ['required', 'integer']])['con']);

        if ($con->tipo_de_condicion_id !== $opcion->tipo_de_condicion_id) {
            throw ValidationException::withMessages(['con' => 'Sólo se pueden unir datos de la misma condición.']);
        }

        return ['fichas' => $this->catalogo->unirOpciones($opcion, $con), 'mensaje' => "Quedó todo en «{$con->texto}»."];
    }

    public function destroyOpcion(Request $request, OpcionDeCondicion $opcion)
    {
        $this->soloAdministradores($request);
        $usos = $opcion->campos()->count();

        if ($usos > 0) {
            throw ValidationException::withMessages(['opcion' => "Lo usan {$usos} fichas: desactivalo, o unilo con otro."]);
        }

        $opcion->delete();

        return response()->noContent();
    }

    /** Un dato que está en fichas pero no en la lista: se suma a la lista y se vincula. */
    public function adoptar(Request $request)
    {
        $this->soloAdministradores($request);
        $datos = $request->validate(['titulo' => ['required', 'string', 'max:60'], 'valor' => ['required', 'string', 'max:255']]);
        $r = $this->catalogo->adoptar($datos['titulo'], $datos['valor']);

        return ['opcion' => $r['opcion'], 'fichas' => $r['fichas'], 'mensaje' => "Agregado a la lista ({$r['fichas']} fichas)."];
    }

    private function soloAdministradores(Request $request): void
    {
        abort_unless($request->user()->role === 'Administrador', 403, 'Sólo los administradores pueden cambiar la lista de condiciones.');
    }
}
