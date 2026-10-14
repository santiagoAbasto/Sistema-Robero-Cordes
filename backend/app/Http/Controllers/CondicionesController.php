<?php

namespace App\Http\Controllers;

use App\Models\CondicionHabitual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Los juegos de condiciones —Importacion, Stock, Reventa—, en Configuracion.
 *
 * Lista de ajustes del cliente, 14-10-2026: "condiciones (reventa,
 * importacion, stock) administrables en configuracion". Los parrafos de
 * Reventa estaban vacios a proposito esperando que CORDES los escribiera;
 * ahora los escribe la empresa misma, en esta pantalla.
 *
 * Una cotizacion copia el texto de sus condiciones al armarse: editar o
 * borrar un parrafo aca no cambia ninguna cotizacion ya hecha.
 */
class CondicionesController extends Controller
{
    public function index()
    {
        return [
            'juegos' => CondicionHabitual::JUEGOS,
            'condiciones' => CondicionHabitual::orderBy('juego')->orderBy('orden')
                ->get(['id', 'juego', 'titulo', 'texto', 'orden', 'activo', 'por_defecto']),
        ];
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $datos['orden'] = (int) CondicionHabitual::where('juego', $datos['juego'])->max('orden') + 1;

        return response()->json(CondicionHabitual::create($datos + ['activo' => true, 'por_defecto' => true]), 201);
    }

    public function update(Request $request, CondicionHabitual $condicion)
    {
        $condicion->update($this->validar($request, parcial: true));

        return $condicion->fresh();
    }

    /** El orden dentro del juego: asi salen en la hoja. */
    public function ordenar(Request $request)
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        foreach (array_values($ids) as $i => $id) {
            CondicionHabitual::whereKey($id)->update(['orden' => $i + 1]);
        }

        return response()->noContent();
    }

    public function destroy(CondicionHabitual $condicion)
    {
        $condicion->delete();

        return response()->noContent();
    }

    private function validar(Request $request, bool $parcial = false): array
    {
        $a = $parcial ? ['sometimes'] : [];

        return $request->validate([
            'juego' => [...$a, 'required', Rule::in(CondicionHabitual::JUEGOS)],
            'titulo' => ['sometimes', 'nullable', 'string', 'max:60'],
            'texto' => [...$a, 'required', 'string', 'max:5000'],
            // Por defecto: viene puesto al elegir el juego. Si no, se agrega a mano.
            'por_defecto' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ]);
    }
}
