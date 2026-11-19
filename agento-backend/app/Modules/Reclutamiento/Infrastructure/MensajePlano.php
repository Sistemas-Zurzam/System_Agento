<?php

namespace App\Modules\Reclutamiento\Infrastructure;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Correo de texto plano que se envía por cola, para que el endpoint público
 * no dependa de la latencia ni de la caída del proveedor de correo.
 *
 * Contiene códigos de acceso: va cifrado en la tabla de la cola
 * (ShouldBeEncrypted) y el fallo se registra sin destinatario ni contenido.
 */
class MensajePlano extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public string $asunto, public string $cuerpo) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.plano');
    }

    public function failed(Throwable $e): void
    {
        Log::error('Reclutamiento: no se pudo enviar el correo encolado.', ['error' => $e->getMessage()]);
    }
}
