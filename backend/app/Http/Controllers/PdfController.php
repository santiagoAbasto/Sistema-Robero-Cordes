<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
use App\Models\Impresion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La hoja que recibe el cliente.
 *
 * Los datos de contacto que salen acá se eligen en el momento de imprimir y
 * valen sólo para esta hoja: la ficha de la empresa no se modifica.
 */
class PdfController extends Controller
{
    /** El mismo isotipo que se ve al entrar al sistema. */
    private const MARCA = 'images/cordes-mark.svg';

    /**
     * La marca, embebida en la hoja.
     *
     * Va como data URI y no como ruta: el PDF se arma del lado del servidor y
     * no tiene que depender de que el front este publicado ni de por donde se
     * sirva. Si el archivo faltara, la hoja sale sin isotipo pero sale: una
     * cotizacion no se cae por una imagen.
     */
    private function marca(): ?string
    {
        $archivo = resource_path(self::MARCA);

        if (! is_file($archivo)) {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($archivo));
    }

    public function cotizacion(Request $request, Consulta $consulta)
    {
        $datos = $request->validate([
            'nombre_en_pdf' => ['nullable', 'string', 'max:150'],
            'contacto_id' => ['nullable', 'exists:contactos,id'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'mail' => ['nullable', 'string', 'max:120'],
            'incluye_importes' => ['nullable', 'boolean'],
            'incluye_nota' => ['nullable', 'boolean'],
            'via' => ['nullable', Rule::in(['Impresora', 'PDF', 'Correo', 'WhatsApp'])],
            'registrar' => ['nullable', 'boolean'],
        ]);

        $consulta->load([
            'empresa.contactos.medios.tipoMedio',
            'lineas.unidadVenta', 'lineas.unidadFactura', 'lineas.opciones.material',
            'condiciones', 'usuario', 'moneda', 'razonSocial',
        ]);

        // Si no eligieron nada, se usa lo que ya tiene la ficha.
        $contacto = isset($datos['contacto_id'])
            ? $consulta->empresa->contactos->firstWhere('id', $datos['contacto_id'])
            : ($consulta->contacto_id
                ? $consulta->empresa->contactos->firstWhere('id', $consulta->contacto_id)
                : $consulta->empresa->contactoPrincipal());

        $nombreEnPdf = $datos['nombre_en_pdf']
            ?? $consulta->razonSocial?->razon_social
            ?? $consulta->empresa->nombre;

        $telefono = $datos['telefono']
            ?? $contacto?->medio('Telefono')?->valor
            ?? $contacto?->medio('WhatsApp')?->valor;

        $mail = $datos['mail'] ?? $contacto?->medio('Mail')?->valor;

        $incluyeImportes = $datos['incluye_importes'] ?? true;
        $incluyeNota = $datos['incluye_nota'] ?? false;

        $lineas = $consulta->lineas->where('quitada', false);

        $titulo = match ($consulta->tipo) {
            'Pedido' => 'Pedido',
            'Observacion' => 'Observacion',
            default => 'Cotizacion',
        };

        $pdf = Pdf::loadView('pdf.cotizacion', [
            'consulta' => $consulta,
            'lineas' => $lineas,
            // Las marcadas como internas no salen: son referencias que la
            // empresa usa para cruzar con su otro sistema, no condiciones
            // comerciales.
            'condiciones' => $consulta->condiciones->where('imprime', true),
            'total' => (float) $lineas->sum('importe'),
            'titulo' => $titulo,
            'marca' => $this->marca(),
            'simbolo' => $consulta->moneda?->simbolo() ?? '',
            'fecha' => $consulta->fecha?->format('d/m/Y'),
            'nombreEnPdf' => $nombreEnPdf,
            'contacto' => $contacto?->nombre,
            'telefono' => $telefono,
            'mail' => $mail,
            'incluyeImportes' => $incluyeImportes,
            'incluyeNota' => $incluyeNota,
        ])->setPaper('a4');

        // Queda anotado a quién se le mandó y por qué vía.
        if ($request->boolean('registrar', true)) {
            Impresion::create([
                'consulta_id' => $consulta->id,
                'fecha' => now(),
                'usuario_id' => $request->user()->id,
                'nombre_en_pdf' => $nombreEnPdf,
                'contacto_id' => $contacto?->id,
                'telefono' => $telefono,
                'mail' => $mail,
                'via' => $datos['via'] ?? 'PDF',
                'vencida_al_mandar' => $consulta->esta_vencida,
                'incluye_importes' => $incluyeImportes,
                'incluye_nota' => $incluyeNota,
            ]);

            $consulta->anotarCambio(
                'Impresion',
                'via',
                null,
                ($datos['via'] ?? 'PDF').' a '.($contacto?->nombre ?? $nombreEnPdf)
            );
        }

        $archivo = str($titulo.'-'.$nombreEnPdf.'-'.$consulta->fecha?->format('Y-m-d'))
            ->slug()->append('.pdf')->value();

        return $request->boolean('descargar')
            ? $pdf->download($archivo)
            : $pdf->stream($archivo);
    }
}
