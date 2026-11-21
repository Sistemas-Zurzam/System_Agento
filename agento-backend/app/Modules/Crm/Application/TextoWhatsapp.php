<?php

namespace App\Modules\Crm\Application;

/**
 * Texto que se muestra de un mensaje entrante de WhatsApp. Los archivos no se descargan (aún):
 * se describen con su pie de foto si lo traen.
 */
final class TextoWhatsapp
{
    private const ARCHIVOS = [
        'image' => 'Imagen',
        'video' => 'Video',
        'audio' => 'Audio',
        'document' => 'Documento',
        'sticker' => 'Sticker',
    ];

    /**
     * @param  array<string, mixed>  $mensaje  elemento de value.messages[] de Meta
     */
    public static function de(array $mensaje): string
    {
        $tipo = (string) ($mensaje['type'] ?? '');

        if (isset(self::ARCHIVOS[$tipo])) {
            $pie = trim((string) ($mensaje[$tipo]['caption'] ?? $mensaje[$tipo]['filename'] ?? ''));

            return '['.self::ARCHIVOS[$tipo].']'.($pie !== '' ? ' '.$pie : '');
        }

        $texto = match ($tipo) {
            'text' => (string) ($mensaje['text']['body'] ?? ''),
            'button' => (string) ($mensaje['button']['text'] ?? ''),
            'interactive' => (string) ($mensaje['interactive']['button_reply']['title'] ?? $mensaje['interactive']['list_reply']['title'] ?? ''),
            'reaction' => '[Reacción] '.($mensaje['reaction']['emoji'] ?? ''),
            'location' => '[Ubicación] '.trim(implode(' · ', array_filter([
                isset($mensaje['location']['latitude'], $mensaje['location']['longitude'])
                    ? $mensaje['location']['latitude'].', '.$mensaje['location']['longitude'] : null,
                $mensaje['location']['name'] ?? null,
                $mensaje['location']['address'] ?? null,
            ]))),
            'contacts' => '[Contacto compartido]',
            default => '[Mensaje de tipo '.($tipo !== '' ? $tipo : 'desconocido').' no soportado]',
        };

        return trim($texto) !== '' ? $texto : '[Mensaje vacío]';
    }
}
