<?php

namespace App\Http\Controllers;

use App\Models\Caracteristica;
use App\Models\MotivoCambio;
use App\Models\MotivoNoCotiza;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Las listas que la empresa edita en Configuracion > Listas.
 *
 * Lista de ajustes del cliente, 14-10-2026: "editable en configuracion la
 * lista de caracteristicas", y lo mismo los motivos de "no se cotiza". Hasta
 * ahora crecian solas con lo que se escribia en una linea, pero no habia
 * donde corregir una mal escrita ni sacar una que ya no se usa.
 *
 * Las tres guardan solo el nombre, y las lineas copian el texto: corregir o
 * borrar un item de la lista no cambia ninguna cotizacion hecha.
 */
class ListasController extends Controller
{
    private const LISTAS = [
        'caracteristicas' => Caracteristica::class,
        'motivos_cambio' => MotivoCambio::class,
        'motivos_no_cotiza' => MotivoNoCotiza::class,
    ];

    public function index()
    {
        return collect(self::LISTAS)->map(
            fn (string $modelo) => $modelo::orderBy('orden')->orderBy('nombre')->get(['id', 'nombre', 'orden', 'activo'])
        );
    }

    public function store(Request $request, string $lista)
    {
        $modelo = $this->modelo($lista);
        $nombre = trim($request->validate(['nombre' => ['required', 'string', 'max:120']])['nombre']);
        $this->sinRepetir($modelo, $nombre);

        return response()->json(
            $modelo::create(['nombre' => $nombre, 'orden' => (int) $modelo::max('orden') + 1, 'activo' => true]),
            201,
        );
    }

    public function update(Request $request, string $lista, int $id)
    {
        $modelo = $this->modelo($lista);
        $item = $modelo::findOrFail($id);

        $datos = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        if (isset($datos['nombre'])) {
            $datos['nombre'] = trim($datos['nombre']);
            $this->sinRepetir($modelo, $datos['nombre'], $item->id);
        }

        $item->update($datos);

        return $item->fresh();
    }

    /** El orden en que aparecen al cotizar: los ids, del primero al ultimo. */
    public function ordenar(Request $request, string $lista)
    {
        $modelo = $this->modelo($lista);
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        foreach (array_values($ids) as $i => $id) {
            $modelo::whereKey($id)->update(['orden' => $i + 1]);
        }

        return response()->noContent();
    }

    public function destroy(string $lista, int $id)
    {
        $this->modelo($lista)::findOrFail($id)->delete();

        return response()->noContent();
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    private function modelo(string $lista): string
    {
        abort_unless(isset(self::LISTAS[$lista]), 404);

        return self::LISTAS[$lista];
    }

    /** Sin repetir: "Sin costura" y "SIN COSTURA " son el mismo. */
    private function sinRepetir(string $modelo, string $nombre, ?int $salvo = null): void
    {
        $existe = $modelo::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->when($salvo, fn ($q) => $q->where('id', '!=', $salvo))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['nombre' => 'Ya esta en la lista.']);
        }
    }
}
