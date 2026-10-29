<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La hoja que se le manda al cliente, ya adjunta.
 *
 * Sale desde la casilla de la empresa, pero la respuesta del cliente le
 * llega a quien la mando, y esa persona recibe una copia oculta.
 */
class CotizacionPorCorreo extends Mailable
{
    public function __construct(
        private Address $remitente,
        private Address $quienLaManda,
        private string $asunto,
        private string $cuerpo,
        private string $pdf,
        private string $archivo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->remitente,
            replyTo: [$this->quienLaManda],
            bcc: [$this->quienLaManda],
            subject: $this->asunto,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->cuerpo)));
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->archivo)->withMime('application/pdf')];
    }
}
