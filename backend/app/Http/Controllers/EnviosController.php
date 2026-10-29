<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Services\Correo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Configuracion > Envio de cotizaciones.
 *
 * Pedido del cliente (29-10-2026): "¿donde se modifica el texto base?" y "el
 * mail salio sin la cotizacion adjuntada". El texto era fijo en el sistema;
 * ahora lo escribe la empresa. Y con la casilla cargada, el correo lo manda
 * el servidor con la hoja adjunta (un enlace mailto: no puede adjuntar).
 *
 * Los textos los lee cualquiera que mande una cotizacion; cambiarlos y ver la
 * casilla, solo un administrador. La clave nunca vuelve al navegador.
 */
class EnviosController extends Controller
{
    private function soloAdministradores(Request $request): void
    {
        abort_unless(
            $request->user()?->role === 'Administrador',
            403,
            'Solo un administrador puede cambiar el envío de cotizaciones.',
        );
    }

    public function index(Request $request)
    {
        $esAdmin = $request->user()?->role === 'Administrador';

        return [
            'texto_whatsapp' => Ajuste::valor('texto_whatsapp', Correo::TEXTO_WHATSAPP),
            'texto_correo' => Ajuste::valor('texto_correo', Correo::TEXTO_CORREO),
            'correo_listo' => Correo::lista(),
            'es_admin' => $esAdmin,
            'casilla' => $esAdmin ? [
                'host' => Ajuste::valor('correo_host'),
                'puerto' => Ajuste::valor('correo_puerto'),
                'usuario' => Ajuste::valor('correo_usuario'),
                'remitente' => Ajuste::valor('correo_remitente'),
                'nombre' => Ajuste::valor('correo_nombre'),
                'tiene_clave' => Correo::clave() !== null,
            ] : null,
        ];
    }

    public function guardar(Request $request)
    {
        $this->soloAdministradores($request);

        $datos = $request->validate([
            'texto_whatsapp' => ['required', 'string', 'max:2000'],
            'texto_correo' => ['required', 'string', 'max:5000'],
            'host' => ['nullable', 'string', 'max:120'],
            'puerto' => ['nullable', 'integer', 'between:1,65535'],
            'usuario' => ['nullable', 'string', 'max:120'],
            // Vacia: queda la que estaba. Para sacarla, borrar_clave.
            'clave' => ['nullable', 'string', 'max:200'],
            'borrar_clave' => ['nullable', 'boolean'],
            'remitente' => ['nullable', 'email', 'max:120'],
            'nombre' => ['nullable', 'string', 'max:120'],
        ], [
            'remitente.email' => 'El remitente tiene que ser una dirección de correo.',
        ]);

        Ajuste::guardar('texto_whatsapp', $datos['texto_whatsapp']);
        Ajuste::guardar('texto_correo', $datos['texto_correo']);

        foreach (['host', 'puerto', 'usuario', 'remitente', 'nombre'] as $campo) {
            Ajuste::guardar('correo_'.$campo, isset($datos[$campo]) ? trim((string) $datos[$campo]) : null);
        }

        if ($request->boolean('borrar_clave')) {
            Ajuste::guardar('correo_clave', null);
        } elseif (filled($datos['clave'] ?? null)) {
            Ajuste::guardar('correo_clave', Crypt::encryptString($datos['clave']));
        }

        return $this->index($request);
    }

    /** Un correo a quien lo pide, para saber si la casilla anda antes de mandarle algo a un cliente. */
    public function prueba(Request $request)
    {
        $this->soloAdministradores($request);

        abort_unless(Correo::lista(), 409, 'Falta cargar el servidor, el puerto y el remitente de la casilla.');

        [$direccion, $nombre] = Correo::remitente();
        $usuario = $request->user();

        try {
            Correo::mailer()->raw(
                'Si te llegó este correo, la casilla quedó bien cargada: las cotizaciones van a salir desde '
                .$direccion.' con la hoja adjunta.',
                fn ($m) => $m->from($direccion, $nombre)->to($usuario->email, $usuario->name)
                    ->subject('Prueba del envío de cotizaciones'),
            );
        } catch (TransportExceptionInterface $e) {
            return response()->json(['message' => 'La casilla no respondió: '.$e->getMessage()], 502);
        }

        return ['mensaje' => "Listo: te mandamos un correo de prueba a {$usuario->email}. Si no llega en unos minutos, fijate en no deseados."];
    }
}
