<?php

namespace App\Modules\Reclutamiento\Infrastructure;

use App\Modules\Reclutamiento\Jobs\EscanearDocumentoJob;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CV y documentos de candidatos en almacenamiento PRIVADO (disco local por
 * defecto: storage/app/private). Nunca se generan URLs públicas: se entregan
 * mediante endpoints autorizados.
 */
class AlmacenDocumentosCandidato
{
    public function guardarCv(int $empresaId, int $candidatoId, UploadedFile $archivo): DocumentoCandidato
    {
        $this->validarPdf($archivo);

        $disco = config('reclutamiento.cv.disco', 'local');
        $ruta = $archivo->storeAs("reclutamiento/{$empresaId}/candidatos/{$candidatoId}", Str::uuid().'.pdf', $disco);

        return $this->registrar([
            'empresa_id' => $empresaId,
            'candidato_id' => $candidatoId,
            'tipo' => 'cv',
            'disco' => $disco,
            'ruta' => $ruta,
            'nombre_original' => Str::limit($archivo->getClientOriginalName(), 190, ''),
            'mime' => 'application/pdf',
            'tamano' => $archivo->getSize(),
            'sha256' => hash_file('sha256', $archivo->getRealPath()),
        ]);
    }

    /**
     * DNI, CUL u otros documentos de apoyo (PDF, JPG o PNG). Se valida el
     * tipo REAL por contenido, no la extensión ni el MIME declarado.
     */
    public function guardarComplementario(int $empresaId, int $candidatoId, string $tipo, UploadedFile $archivo): DocumentoCandidato
    {
        $extensiones = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $maxBytes = (int) config('reclutamiento.cv.max_kb', 5120) * 1024;
        $mimeReal = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo->getRealPath());

        if ($archivo->getSize() > $maxBytes || ! isset($extensiones[$mimeReal])) {
            throw ValidationException::withMessages([
                $tipo => 'El archivo debe ser PDF, JPG o PNG de hasta '.intdiv($maxBytes, 1024 * 1024).' MB.',
            ]);
        }

        $disco = config('reclutamiento.cv.disco', 'local');
        $ruta = $archivo->storeAs("reclutamiento/{$empresaId}/candidatos/{$candidatoId}", Str::uuid().'.'.$extensiones[$mimeReal], $disco);

        return $this->registrar([
            'empresa_id' => $empresaId,
            'candidato_id' => $candidatoId,
            'tipo' => $tipo,
            'disco' => $disco,
            'ruta' => $ruta,
            'nombre_original' => Str::limit($archivo->getClientOriginalName(), 190, ''),
            'mime' => $mimeReal,
            'tamano' => $archivo->getSize(),
            'sha256' => hash_file('sha256', $archivo->getRealPath()),
        ]);
    }

    /**
     * Guarda el registro (nace `pendiente_escaneo`: no se descarga hasta que el antivirus lo apruebe)
     * y encola su escaneo cuando termine la transacción en curso.
     *
     * @param  array<string, mixed>  $datos
     */
    private function registrar(array $datos): DocumentoCandidato
    {
        $documento = DocumentoCandidato::create($datos + ['estado_escaneo' => DocumentoCandidato::PENDIENTE_ESCANEO]);
        EscanearDocumentoJob::dispatch($documento->empresa_id, $documento->id)->afterCommit();

        return $documento;
    }

    public function descargar(DocumentoCandidato $documento): StreamedResponse
    {
        abort_unless($documento->descargable() || $documento->eliminado_at, 423, match ($documento->estado_escaneo) {
            DocumentoCandidato::INFECTADO => 'El archivo fue bloqueado por el antivirus y no se puede descargar.',
            default => 'El archivo está en revisión de seguridad. Inténtalo de nuevo en unos minutos.',
        });

        abort_if($documento->eliminado_at || ! $documento->ruta || ! Storage::disk($documento->disco)->exists($documento->ruta), 404, 'El archivo no está disponible.');

        return Storage::disk($documento->disco)->response($documento->ruta, $documento->nombre_original, [
            'Content-Type' => $documento->mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function eliminarArchivo(DocumentoCandidato $documento): void
    {
        if ($documento->ruta) {
            Storage::disk($documento->disco)->delete($documento->ruta);
        }

        $documento->forceFill(['ruta' => null, 'eliminado_at' => now()])->save();
    }

    /**
     * Valida el tipo REAL del archivo (firma %PDF- y MIME detectado por
     * contenido), no solo la extensión o el MIME declarado por el cliente.
     */
    private function validarPdf(UploadedFile $archivo): void
    {
        $maxBytes = (int) config('reclutamiento.cv.max_kb', 5120) * 1024;
        $handle = fopen($archivo->getRealPath(), 'rb');
        $cabecera = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }

        $mimeReal = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo->getRealPath());

        if ($archivo->getSize() > $maxBytes || $cabecera !== '%PDF-' || $mimeReal !== 'application/pdf') {
            throw ValidationException::withMessages([
                'cv' => 'El CV debe ser un archivo PDF válido de hasta '.intdiv($maxBytes, 1024 * 1024).' MB.',
            ]);
        }
    }
}
