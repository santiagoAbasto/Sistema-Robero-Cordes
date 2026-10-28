<?php

namespace App\Console\Commands;

use App\Http\Controllers\UsuarioController;
use App\Models\Consulta;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Los usuarios definitivos, todos de una vez, desde una lista.
 *
 * Hasta aca entraban todos con el usuario de prueba, y esa clave la tiene
 * medio mundo. Cada persona pasa a tener el suyo, con una clave nueva.
 *
 * La lista es un CSV (nombre,correo,rol,antes) que se escribe en el servidor y
 * no entra al repositorio: el codigo es publico y los correos de la gente de
 * la empresa no tienen por que estar ahi. "antes" es el correo del usuario que
 * esa persona ya venia usando: se le cambia el correo en vez de crear otro, y
 * sus cotizaciones siguen a su nombre.
 *
 * El que ya tiene su usuario definitivo no se toca: volver a correr la lista
 * para sumar a alguien no le cambia la clave, el rol ni los permisos a los
 * demas.
 *
 * Todo o nada: si algo de la lista no cierra, no se toca ningun usuario.
 *
 * Las claves las pone quien lo corre (--pedir-claves): se tipean escondidas,
 * dos veces cada una, y no quedan en ningun archivo ni en la pantalla, que
 * despues termina en una captura. Sin esa opcion se inventan y van al archivo
 * de --claves-a.
 *
 *   php artisan usuarios:definitivos lista.csv --baja=prueba@cordes.com --pedir-claves --aplicar
 */
class UsuariosDefinitivos extends Command
{
    protected $signature = 'usuarios:definitivos
        {lista : CSV con nombre,correo,rol,antes}
        {--baja=* : correo de un usuario que deja de entrar}
        {--pedir-claves : la clave de cada uno se tipea escondida, en vez de inventarla}
        {--claves-a= : archivo donde quedan las claves inventadas; sin esto salen en pantalla}
        {--aplicar : escribe; sin esto solo muestra lo que haria}';

    protected $description = 'Da de alta los usuarios definitivos desde una lista';

    public function handle(): int
    {
        $filas = $this->leerLista((string) $this->argument('lista'));

        if (is_string($filas)) {
            $this->error($filas);

            return self::FAILURE;
        }

        $errores = [];
        $plan = [];

        foreach ($filas as $n => $f) {
            $porCorreo = User::where('email', $f['correo'])->first();
            $anterior = $f['antes'] !== '' ? User::where('email', $f['antes'])->first() : null;

            // Ya corrida una vez, el "antes" no existe mas y el correo nuevo si:
            // volver a correrla no es un error.
            if ($f['antes'] !== '' && $anterior === null && $porCorreo === null) {
                $errores[] = "Renglon {$n}: no hay ningun usuario con el correo {$f['antes']}.";
            }

            if ($porCorreo && $anterior && $porCorreo->isNot($anterior)) {
                $errores[] = "Renglon {$n}: {$f['correo']} y {$f['antes']} son dos usuarios distintos.";
            }

            $usuario = $porCorreo ?? $anterior;

            if ($usuario && collect($plan)->contains(fn ($p) => $p[1]?->is($usuario))) {
                $errores[] = "Renglon {$n}: el usuario {$usuario->email} ya lo toma otra persona de la lista.";
            }

            $plan[] = [$f, $usuario];
        }

        $enLaLista = collect($plan)->map(fn ($p) => $p[1]?->id)->filter()->values()->all();
        $bajas = [];

        foreach ((array) $this->option('baja') as $correo) {
            $usuario = User::where('email', $correo)->first();

            if ($usuario === null) {
                // Lo que se queria es que no entre, y no entra.
                $this->warn("No hay ningun usuario {$correo}: no hace falta darlo de baja.");
            } elseif (in_array($usuario->id, $enLaLista, true)) {
                $errores[] = "{$correo} esta en la lista y tambien para dar de baja.";
            } else {
                $bajas[] = $usuario;
            }
        }

        // Sin un administrador activo nadie puede volver a entrar a Usuarios.
        $adminsQueQuedan = User::where('role', 'Administrador')->where('activo', true)
            ->whereNotIn('id', [...$enLaLista, ...array_map(fn ($u) => $u->id, $bajas)])
            ->count();

        if ($adminsQueQuedan === 0 && ! collect($filas)->contains('rol', 'Administrador')) {
            $errores[] = 'El sistema quedaria sin ningun administrador activo.';
        }

        $this->table(['nombre', 'correo', 'rol', 'que pasa'], array_map(fn ($p) => [
            $p[0]['nombre'],
            $p[0]['correo'],
            $p[0]['rol'],
            match (true) {
                $p[1] === null => 'usuario nuevo',
                $p[1]->email === $p[0]['correo'] => 'ya estaba: no se toca',
                default => "toma el usuario {$p[1]->email} (".Consulta::where('usuario_id', $p[1]->id)->count().' cotizaciones)',
            },
        ], $plan));

        if ($bajas !== []) {
            $this->table(['deja de entrar', 'correo'], array_map(fn ($u) => [$u->name, $u->email], $bajas));
        }

        if ($errores !== []) {
            foreach ($errores as $error) {
                $this->error($error);
            }
            $this->error('No se toco ningun usuario.');

            return self::FAILURE;
        }

        if (! $this->option('aplicar')) {
            $this->warn('Modo informe: no se escribio nada. Agregá --aplicar para hacerlo.');

            return self::SUCCESS;
        }

        // Todas antes de escribir: si se corta a mitad de camino, no queda
        // nadie con la clave a medio poner.
        $elegidas = [];

        if ($this->option('pedir-claves')) {
            foreach ($plan as [$f, $usuario]) {
                if ($usuario?->email !== $f['correo']) {
                    $elegidas[$f['correo']] = $this->pedirClave($f['nombre'], $f['correo']);
                }
            }
        }

        $claves = DB::transaction(function () use ($plan, $bajas, $elegidas) {
            $claves = [];

            foreach ($plan as [$f, $usuario]) {
                if ($usuario?->email === $f['correo']) {
                    continue;
                }

                $usuario ??= new User;
                $clave = $elegidas[$f['correo']] ?? Str::password(12, symbols: false);

                $usuario->fill([
                    'name' => $f['nombre'],
                    'email' => $f['correo'],
                    'role' => $f['rol'],
                    'activo' => true,
                ]);
                $usuario->password = Hash::make($clave);
                $usuario->save();

                // Todos arrancan como un usuario recien creado desde la
                // pantalla: ven todas las fichas, cotizan e imprimen. Lo que
                // traia el usuario de prueba no es una decision de la empresa.
                // Se ajusta despues en "Quien ve que".
                Permiso::where('user_id', $usuario->id)->delete();

                $claves[] = [$f['nombre'], $f['correo'], $clave];
            }

            foreach ($bajas as $usuario) {
                $usuario->update(['activo' => false]);
            }

            return $claves;
        });

        $this->newLine();

        if ($claves === []) {
            $this->info('Ya estaban todos: no se cambio nada.');
        } elseif ($elegidas !== []) {
            $this->info(count($claves).' usuarios listos, con las claves que pusiste.');
        } elseif ($archivo = $this->option('claves-a')) {
            file_put_contents($archivo, implode(PHP_EOL, array_map(fn ($c) => implode('    ', $c), $claves)).PHP_EOL);
            $this->info(count($claves).' usuarios listos. Las claves quedaron en '.$archivo);
            $this->line('Mandale a cada uno la suya y despues borra ese archivo.');
        } else {
            $this->table(['nombre', 'correo', 'clave'], $claves);
        }

        return self::SUCCESS;
    }

    /** Hasta que las dos coincidan y tenga el largo minimo de toda clave del sistema. */
    private function pedirClave(string $nombre, string $correo): string
    {
        while (true) {
            $clave = (string) $this->secret("Clave para {$nombre} ({$correo})");

            if (mb_strlen($clave) < 10) {
                $this->error('Al menos diez caracteres. Otra vez.');

                continue;
            }

            if ($clave === (string) $this->secret('Repetila')) {
                return $clave;
            }

            $this->error('Las dos no coinciden. Otra vez.');
        }
    }

    /** @return array<int, array{nombre: string, correo: string, rol: string, antes: string}>|string */
    private function leerLista(string $ruta): array|string
    {
        $texto = is_file($ruta) ? file_get_contents($ruta) : false;

        if ($texto === false) {
            return "No pude leer {$ruta}.";
        }

        // El Bloc de notas y PowerShell le ponen BOM al UTF-8.
        $lineas = preg_split('/\R/', preg_replace('/^\xEF\xBB\xBF/', '', $texto));
        $cabecera = array_map('trim', str_getcsv((string) array_shift($lineas), ',', '"', ''));

        if (array_slice($cabecera, 0, 3) !== ['nombre', 'correo', 'rol']) {
            return 'La primera linea tiene que ser: nombre,correo,rol,antes';
        }

        $filas = [];
        $errores = [];

        foreach ($lineas as $i => $linea) {
            if (trim($linea) === '' || str_starts_with(trim($linea), '#')) {
                continue;
            }

            $n = $i + 2;
            [$nombre, $correo, $rol, $antes] = array_pad(array_map('trim', str_getcsv($linea, ',', '"', '')), 4, '');
            $correo = mb_strtolower($correo);
            $antes = mb_strtolower($antes);

            if ($nombre === '') {
                $errores[] = "Renglon {$n}: falta el nombre.";
            }

            if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = "Renglon {$n}: \"{$correo}\" no es un correo.";
            }

            if (! in_array($rol, UsuarioController::ROLES, true)) {
                $errores[] = "Renglon {$n}: el rol \"{$rol}\" no existe (".implode(', ', UsuarioController::ROLES).').';
            }

            if (collect($filas)->contains('correo', $correo)) {
                $errores[] = "Renglon {$n}: {$correo} ya esta mas arriba.";
            }

            $filas[$n] = compact('nombre', 'correo', 'rol', 'antes');
        }

        if ($errores !== []) {
            return implode(PHP_EOL, [...$errores, 'No se toco ningun usuario.']);
        }

        return $filas === [] ? 'La lista no tiene a nadie.' : $filas;
    }
}
