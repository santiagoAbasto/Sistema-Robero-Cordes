<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Qué fichas ve y qué puede hacer cada usuario. */
class PermisoController extends Controller
{
    public function index()
    {
        $usuarios = User::with('permiso')->where('activo', true)->orderBy('name')->get();

        return [
            'usuarios' => $usuarios->map(fn ($u) => [
                'id' => $u->id,
                'nombre' => $u->name,
                'iniciales' => $u->initials,
                'rol' => $u->role,
                'es_admin' => $u->role === 'Administrador',
                'permisos' => [
                    've_fichas' => $u->permiso?->ve_fichas ?? 'Todas',
                    've_importes' => $u->permiso?->ve_importes ?? true,
                    've_notas_de_otros' => $u->permiso?->ve_notas_de_otros ?? false,
                    'puede_modificar' => $u->permiso?->puede_modificar ?? true,
                    'puede_imprimir' => $u->permiso?->puede_imprimir ?? true,
                    'puede_archivar' => $u->permiso?->puede_archivar ?? false,
                    've_control_cambios' => $u->permiso?->ve_control_cambios ?? false,
                ],
            ]),
            // Las pocas fichas que se quieren reservar.
            'reservadas' => Empresa::where('visible_para', '!=', 'Todos')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'visible_para']),
        ];
    }

    public function actualizar(Request $request, User $usuario)
    {
        // Los dueños ven todo siempre, para que nunca quede una ficha que
        // nadie pueda abrir.
        if ($usuario->role === 'Administrador') {
            return response()->json([
                'message' => 'A un administrador no se le pueden quitar permisos.',
            ], 422);
        }

        $datos = $request->validate([
            've_fichas' => ['required', Rule::in(['Todas', 'Solo las suyas', 'Solo las de un grupo'])],
            've_importes' => ['boolean'],
            've_notas_de_otros' => ['boolean'],
            'puede_modificar' => ['boolean'],
            'puede_imprimir' => ['boolean'],
            'puede_archivar' => ['boolean'],
            've_control_cambios' => ['boolean'],
        ]);

        Permiso::updateOrCreate(['user_id' => $usuario->id], $datos);

        return response()->json(['mensaje' => 'Permisos guardados.']);
    }

    /** Reservar o liberar una ficha puntual. */
    public function visibilidad(Request $request, Empresa $empresa)
    {
        $datos = $request->validate([
            'visible_para' => ['required', Rule::in(['Todos', 'Solo los dueños', 'Un grupo'])],
        ]);

        $empresa->update($datos);

        return response()->json(['mensaje' => 'Listo. La ficha cambió de visibilidad.']);
    }
}
