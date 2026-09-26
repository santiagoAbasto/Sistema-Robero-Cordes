<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Cambia la clave de un usuario sin que la clave toque el repositorio.
 *
 * Los seeders traen una clave de desarrollo escrita en el codigo, que es
 * comoda para levantar el sistema en una maquina nueva y es exactamente lo
 * que NO puede quedar en un servidor con datos reales: el codigo esta en
 * GitHub, asi que esa clave la puede leer cualquiera.
 *
 * Se tipea escondida y no queda en el historial del shell. Con DB_URL delante
 * apunta al servidor en vez de a la base local:
 *
 *   DB_URL='mysql://root:...@host.proxy.rlwy.net:12345/railway' \
 *     php artisan usuarios:clave roberto@cordes.ar
 */
class CambiarClave extends Command
{
    protected $signature = 'usuarios:clave {email? : el correo del usuario}';

    protected $description = 'Cambia la clave de un usuario, preguntandola escondida';

    public function handle(): int
    {
        $usuarios = User::query()->orderBy('id')->get(['id', 'name', 'email', 'role']);

        if ($usuarios->isEmpty()) {
            $this->error('No hay usuarios en esta base. ¿Estas apuntando a la base correcta?');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        if ($email === null) {
            $this->newLine();
            $this->table(['id', 'nombre', 'correo', 'rol'], $usuarios->map(
                fn ($u) => [$u->id, $u->name, $u->email, $u->role],
            )->all());
            $this->line('Elegí uno: php artisan usuarios:clave <correo>');

            return self::SUCCESS;
        }

        $usuario = $usuarios->firstWhere('email', $email);

        if ($usuario === null) {
            $this->error("No hay ningun usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $this->info("Cambiando la clave de {$usuario->name} ({$usuario->email}).");

        $clave = $this->secret('Clave nueva');
        $otraVez = $this->secret('Repetila');

        if ($clave !== $otraVez) {
            $this->error('Las dos no coinciden. No se cambio nada.');

            return self::FAILURE;
        }

        if (mb_strlen((string) $clave) < 10) {
            $this->error('Al menos diez caracteres: el sistema esta en una direccion publica.');

            return self::FAILURE;
        }

        // update() directo y no save(): una clave no es un cambio comercial
        // que tenga que quedar en el historial de la cotizacion.
        User::whereKey($usuario->id)->update(['password' => Hash::make($clave)]);

        $this->newLine();
        $this->info('Listo. La clave nueva no quedo escrita en ningun archivo.');

        return self::SUCCESS;
    }
}
