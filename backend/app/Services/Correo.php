<?php

namespace App\Services;

use App\Models\Ajuste;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * La casilla de la empresa desde la que el sistema manda los correos.
 *
 * La carga un administrador en Configuracion, no el .env: asi la clave no
 * pasa por nadie mas ni se tipea por el escritorio remoto, y la pueden
 * cambiar ellos el dia que la roten. Se guarda cifrada con la APP_KEY.
 */
class Correo
{
    public const TEXTO_WHATSAPP = 'Hola, les paso la cotización de Roberto Cordes S.A. para {empresa}: '
        .'{cotizacion}. Cualquier duda, quedamos a disposición.';

    public const TEXTO_CORREO = "Hola, les paso adjunta la cotización de Roberto Cordes S.A. para {empresa}: {cotizacion}.\n\n"
        ."Cualquier duda, quedamos a disposición.\n\nSaludos,\n{quien_manda}\nRoberto Cordes S.A.";

    /** Con servidor, puerto y remitente ya se puede mandar; usuario y clave dependen del servidor. */
    public static function lista(): bool
    {
        return filled(Ajuste::valor('correo_host'))
            && filled(Ajuste::valor('correo_puerto'))
            && filled(Ajuste::valor('correo_remitente'));
    }

    /** La clave guardada, o null si no hay o ya no se puede leer (cambio la APP_KEY). */
    public static function clave(): ?string
    {
        $cifrada = Ajuste::valor('correo_clave');

        if (blank($cifrada)) {
            return null;
        }

        try {
            return Crypt::decryptString($cifrada);
        } catch (DecryptException) {
            return null;
        }
    }

    public static function mailer(): Mailer
    {
        $puerto = (int) Ajuste::valor('correo_puerto');

        config(['mail.mailers.cordes' => [
            'transport' => 'smtp',
            // 465 es SSL desde el saludo; el resto (587, 25) pasa a TLS solo si el servidor lo ofrece.
            'scheme' => $puerto === 465 ? 'smtps' : 'smtp',
            'host' => Ajuste::valor('correo_host'),
            'port' => $puerto,
            'username' => Ajuste::valor('correo_usuario'),
            'password' => self::clave(),
            // Un servidor que no contesta no deja la pantalla colgada.
            'timeout' => 20,
        ]]);

        // Si en el mismo pedido se cambio la casilla, que no use la vieja.
        Mail::purge('cordes');

        return Mail::mailer('cordes');
    }

    /** @return array{0: string, 1: string} direccion y nombre con que sale */
    public static function remitente(): array
    {
        return [
            (string) Ajuste::valor('correo_remitente'),
            Ajuste::valor('correo_nombre') ?: 'Roberto Cordes S.A.',
        ];
    }

    /** {empresa}, {cotizacion} y {quien_manda} se completan solos. */
    public static function completar(string $plantilla, array $valores): string
    {
        return strtr($plantilla, [
            '{empresa}' => $valores['empresa'] ?? '',
            '{cotizacion}' => $valores['cotizacion'] ?? '',
            '{quien_manda}' => $valores['quien_manda'] ?? '',
        ]);
    }
}
