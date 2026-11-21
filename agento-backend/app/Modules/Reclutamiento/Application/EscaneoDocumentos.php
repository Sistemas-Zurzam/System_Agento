<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Auditoria\Models\AuditoriaEvento;
use App\Modules\Reclutamiento\Domain\AntivirusNoDisponibleException;
use App\Modules\Reclutamiento\Domain\Contracts\EscanerArchivos;
use App\Modules\Reclutamiento\Domain\ResultadoEscaneo;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use App\Modules\Reclutamiento\Models\Tarea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Escanea un documento de candidato y aplica el resultado:
 *  - limpio: queda disponible para descarga;
 *  - infectado: cuarentena (el archivo se mueve a cuarentena/ y NO se puede descargar), evento en
 *    Auditoría y tarea para RR. HH.;
 *  - antivirus no disponible: según reclutamiento.antivirus.si_no_disponible. "rechazar" (por defecto en
 *    producción) lanza una excepción para reintentar y el archivo sigue sin poder descargarse
 *    (falla CERRADO); "permitir" (por defecto en local/testing) lo da por limpio dejando constancia.
 */
class EscaneoDocumentos
{
    public function __construct(private readonly EscanerArchivos $escaner) {}

    public function procesar(DocumentoCandidato $documento): string
    {
        if ($documento->eliminado_at || ! $documento->ruta) {
            return $documento->estado_escaneo;
        }

        $disco = Storage::disk($documento->disco);
        $flujo = $disco->exists($documento->ruta) ? $disco->readStream($documento->ruta) : null;
        if (! is_resource($flujo)) {
            $resultado = ResultadoEscaneo::noDisponible('El archivo no se encontró en el almacenamiento.');
        } else {
            try {
                $resultado = $this->escaner->escanear($flujo);
            } finally {
                fclose($flujo);
            }
        }

        return match ($resultado->estado) {
            ResultadoEscaneo::LIMPIO => $this->marcar($documento, DocumentoCandidato::LIMPIO, null),
            ResultadoEscaneo::INFECTADO => $this->ponerEnCuarentena($documento, (string) $resultado->detalle),
            default => $this->sinAntivirus($documento, (string) $resultado->detalle),
        };
    }

    /**
     * Marca como "error de escaneo" (sigue sin poder descargarse) cuando se agotaron los reintentos.
     */
    public function marcarError(DocumentoCandidato $documento, string $motivo): void
    {
        $this->marcar($documento, DocumentoCandidato::ERROR_ESCANEO, $motivo);
        Log::error('Reclutamiento: no se pudo escanear un documento tras varios intentos; queda bloqueado.', ['documento_id' => $documento->id]);
    }

    private function sinAntivirus(DocumentoCandidato $documento, string $motivo): string
    {
        if (config('reclutamiento.antivirus.si_no_disponible') === 'permitir') {
            Log::warning('Reclutamiento: antivirus no disponible; el documento se permite por configuración.', ['documento_id' => $documento->id, 'motivo' => $motivo]);

            return $this->marcar($documento, DocumentoCandidato::LIMPIO, 'sin antivirus: '.Str::limit($motivo, 150, ''));
        }

        throw new AntivirusNoDisponibleException($motivo);
    }

    private function marcar(DocumentoCandidato $documento, string $estado, ?string $resultado): string
    {
        $documento->forceFill([
            'estado_escaneo' => $estado,
            'resultado_escaneo' => $resultado ? Str::limit($resultado, 190, '') : null,
            'escaneado_at' => now(),
        ])->save();

        return $estado;
    }

    private function ponerEnCuarentena(DocumentoCandidato $documento, string $firma): string
    {
        DB::transaction(function () use ($documento, $firma) {
            $disco = Storage::disk($documento->disco);
            $destino = "cuarentena/{$documento->empresa_id}/".Str::uuid();
            $disco->move($documento->ruta, $destino);

            $documento->forceFill([
                'ruta' => $destino,
                'estado_escaneo' => DocumentoCandidato::INFECTADO,
                'resultado_escaneo' => Str::limit($firma, 190, ''),
                'escaneado_at' => now(),
            ])->save();

            AuditoriaEvento::create([
                'empresa_id' => $documento->empresa_id,
                'usuario_nombre' => 'sistema (antivirus)',
                'tipo' => 'seguridad',
                'accion' => 'archivo.infectado',
                'modulo' => 'seleccion',
                'metodo' => 'JOB',
                'ruta' => 'reclutamiento/escaneo',
                'recurso_tipo' => 'DocumentoCandidato',
                'recurso_id' => (string) $documento->id,
                'payload' => ['documento_id' => $documento->id, 'candidato_id' => $documento->candidato_id, 'tipo' => $documento->tipo, 'firma' => $firma, 'sha256' => $documento->sha256],
            ]);

            Tarea::create([
                'empresa_id' => $documento->empresa_id,
                'tipo' => Tarea::ARCHIVO_INFECTADO,
                'titulo' => 'Archivo bloqueado por el antivirus',
                'detalle' => "Un documento ({$documento->tipo}) subido por un candidato contenía «{$firma}» y fue puesto en cuarentena. No se puede descargar. Contacta al candidato para que envíe un archivo limpio.",
                'permiso_responsable' => 'seleccion.candidatos.gestionar',
                'estado' => 'pendiente',
            ]);
        });

        Log::critical('Reclutamiento: documento infectado puesto en cuarentena.', ['documento_id' => $documento->id, 'empresa_id' => $documento->empresa_id, 'firma' => $firma]);

        return DocumentoCandidato::INFECTADO;
    }
}
