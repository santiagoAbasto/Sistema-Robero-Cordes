<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Alta, baja y modificacion de usuarios.
 *
 * Antes la pantalla de usuarios solo dejaba tocar los permisos de los que ya
 * estaban: para sumar a alguien habia que pedirlo. Entra un vendedor nuevo y
 * hasta que no se lo cargaba, cotizaba con la clave de otro y el historial
 * quedaba a nombre equivocado.
 *
 * NADIE SE BORRA, se desactiva. Un usuario es el autor de sus cotizaciones y
 * de cada linea del historial: borrarlo dejaria ocho mil cotizaciones sin
 * saber quien las hizo. Desactivado no entra mas y desaparece de las listas,
 * pero su firma sigue donde estaba.
 */
class UsuarioController extends Controller
{
    /** Los roles que existen. Administrador ve todo y toca la configuracion. */
    public const ROLES = ['Administrador', 'Ventas', 'Vendedor', 'Consulta'];

    private function soloAdministradores(Request $request): void
    {
        abort_unless(
            $request->user()?->role === 'Administrador',
            403,
            'Solo un administrador puede dar de alta o modificar usuarios.',
        );
    }

    /** Todos, incluidos los dados de baja: hay que poder volver a activarlos. */
    public function index(Request $request)
    {
        $this->soloAdministradores($request);

        return [
            'roles' => self::ROLES,
            'usuarios' => User::query()->orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id,
                'nombre' => $u->name,
                'iniciales' => $u->iniciales,
                'email' => $u->email,
                'rol' => $u->role,
                'activo' => (bool) $u->activo,
                // Cuanto trabajo tiene firmado: es lo que se perderia de vista
                // si alguien quisiera borrarlo.
                'cotizaciones' => Consulta::where('usuario_id', $u->id)->count(),
            ]),
        ];
    }

    public function guardar(Request $request, ?User $usuario = null)
    {
        $this->soloAdministradores($request);

        $esNuevo = ! $usuario?->exists;

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            // Las iniciales firman el historial y salen en las listas.
            'iniciales' => ['nullable', 'string', 'max:6'],
            'email' => [
                'required', 'email', 'max:120',
                Rule::unique('users', 'email')->ignore($usuario?->id),
            ],
            'rol' => ['required', Rule::in(self::ROLES)],
            'activo' => ['boolean'],
            // Al crear hace falta; al modificar, solo si la quieren cambiar.
            'clave' => [$esNuevo ? 'required' : 'nullable', 'string', 'min:10'],
        ], [
            'email.unique' => 'Ya hay un usuario con ese correo.',
            'clave.min' => 'Al menos diez caracteres: el sistema esta en una direccion publica.',
        ]);

        $usuario = $usuario?->exists ? $usuario : new User;

        // Modificar a alguien dado de baja lo dejaba activo sin avisar: la
        // pantalla no manda "activo" y se tomaba como si.
        $activo = $datos['activo'] ?? ($usuario->exists ? (bool) $usuario->activo : true);

        // Sacarle el rol al ultimo administrador deja el sistema sin nadie que
        // pueda volver a entrar a esta pantalla, igual que darlo de baja.
        $dejaDeSerAdmin = $usuario->exists && $usuario->role === 'Administrador' && $usuario->activo
            && ($datos['rol'] !== 'Administrador' || ! $activo);

        if ($dejaDeSerAdmin && ! User::where('role', 'Administrador')->where('activo', true)->whereKeyNot($usuario->id)->exists()) {
            return response()->json([
                'message' => 'Es el unico administrador activo: el sistema quedaria sin nadie que pueda administrarlo.',
            ], 422);
        }

        $usuario->fill([
            'name' => $datos['nombre'],
            'iniciales' => $datos['iniciales'] ?? null,
            'email' => $datos['email'],
            'role' => $datos['rol'],
            'activo' => $activo,
        ]);

        if (filled($datos['clave'] ?? null)) {
            $usuario->password = Hash::make($datos['clave']);
        }

        $usuario->save();

        return response()->json(
            ['mensaje' => $esNuevo ? 'Usuario creado.' : 'Usuario guardado.', 'id' => $usuario->id],
            $esNuevo ? 201 : 200,
        );
    }

    /**
     * La baja: se desactiva, no se borra.
     *
     * Y no se puede dejar al sistema sin ningun administrador activo: quedaria
     * sin nadie que pueda volver a entrar a esta pantalla.
     */
    public function baja(Request $request, User $usuario)
    {
        $this->soloAdministradores($request);

        if ($usuario->id === $request->user()->id) {
            return response()->json(['message' => 'No te podes dar de baja a vos mismo.'], 422);
        }

        $quedanAdmins = User::where('role', 'Administrador')
            ->where('activo', true)
            ->where('id', '!=', $usuario->id)
            ->exists();

        if ($usuario->role === 'Administrador' && ! $quedanAdmins) {
            return response()->json([
                'message' => 'Es el unico administrador activo: el sistema quedaria sin nadie que pueda administrarlo.',
            ], 422);
        }

        $usuario->update(['activo' => false]);

        return response()->json(['mensaje' => "{$usuario->name} quedo dado de baja."]);
    }

    public function alta(Request $request, User $usuario)
    {
        $this->soloAdministradores($request);

        $usuario->update(['activo' => true]);

        return response()->json(['mensaje' => "{$usuario->name} vuelve a tener acceso."]);
    }
}
