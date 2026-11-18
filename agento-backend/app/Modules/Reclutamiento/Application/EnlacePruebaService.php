<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Infrastructure\CorreoReclutamiento;
use App\Modules\Reclutamiento\Infrastructure\VerificadorGoogle;
use App\Modules\Reclutamiento\Models\Prueba;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use App\Modules\Reclutamiento\Models\PruebaEnlace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enlace compartible de una prueba con inicio de sesión de Google.
 *
 * - RR. HH. obtiene UN enlace por prueba y empresa (estable: se puede
 *   reenviar). Para pruebas del banco de Agento, el enlace queda en la
 *   empresa de quien lo genera y es ahí donde ve a los participantes.
 * - Quien lo abre se identifica con Google; se crea (o reutiliza) su
 *   asignación propia. Un participante = un correo = un único intento: volver
 *   a entrar con la misma cuenta retoma el intento, no lo reinicia.
 * - Desde ahí la prueba se rinde con el flujo normal por token de asignación.
 */
class EnlacePruebaService
{
    public function __construct(
        private readonly VerificadorGoogle $google,
        private readonly AplicacionPruebaService $aplicacion,
        private readonly CorreoReclutamiento $correo,
    ) {}

    /**
     * @return array{enlace: PruebaEnlace, url: string}
     */
    public function obtenerOCrear(AlcanceSeleccion $alcance, Prueba $prueba): array
    {
        if ($prueba->estado !== Prueba::PUBLICADA) {
            throw new TransicionInvalidaException('Solo se comparte el enlace de una prueba publicada.');
        }

        $enlace = PruebaEnlace::firstOrCreate(
            ['empresa_id' => $alcance->empresaId, 'prueba_id' => $prueba->id],
            ['token' => Str::random(48), 'activo' => true, 'creado_por' => $alcance->usuario->id],
        );
        if (! $enlace->activo) {
            $enlace->update(['activo' => true]);
        }

        return ['enlace' => $enlace, 'url' => $this->url($enlace)];
    }

    public function desactivar(AlcanceSeleccion $alcance, Prueba $prueba): void
    {
        PruebaEnlace::where('empresa_id', $alcance->empresaId)->where('prueba_id', $prueba->id)->update(['activo' => false]);
    }

    /**
     * @return array{url: ?string, activo: bool, participantes: Collection}
     */
    public function resumen(AlcanceSeleccion $alcance, Prueba $prueba): array
    {
        $enlace = PruebaEnlace::where('empresa_id', $alcance->empresaId)->where('prueba_id', $prueba->id)->first();
        if (! $enlace) {
            return ['url' => null, 'activo' => false, 'participantes' => collect()];
        }

        $participantes = PruebaAsignacion::withoutGlobalScopes()
            ->where('enlace_id', $enlace->id)
            ->with(['prueba.dimensiones', 'intento.resultados', 'intento.concluidoPor'])
            ->orderByDesc('created_at')
            ->get();

        return ['url' => $this->url($enlace), 'activo' => $enlace->activo, 'participantes' => $participantes];
    }

    /**
     * Datos mínimos de la prueba para la pantalla previa al inicio de sesión.
     */
    public function informacion(string $token): Prueba
    {
        return $this->enlaceVigente($token)->prueba;
    }

    /**
     * Identifica al participante con Google y devuelve el token de SU
     * asignación (se emite uno nuevo en cada ingreso; el anterior deja de
     * servir, igual que "regenerar enlace" del candidato).
     *
     * @return array{token: string, asignacion: PruebaAsignacion}
     */
    public function acceder(string $token, string $credencial): array
    {
        $enlace = $this->enlaceVigente($token);
        $persona = $this->google->verificar($credencial);

        $asignacion = $this->asignacionDe($enlace, $persona);

        if (in_array($asignacion->estado, [PruebaAsignacion::PENDIENTE, PruebaAsignacion::EN_CURSO], true)) {
            $acceso = $this->aplicacion->regenerarToken($asignacion);
        } else {
            // Ya finalizada o vencida: se entrega un token solo para mostrar
            // el estado (no permite rendirla de nuevo).
            $acceso = Str::random(64);
            $asignacion->forceFill(['token_hash' => hash('sha256', $acceso)])->save();
        }

        return ['token' => $acceso, 'asignacion' => $asignacion];
    }

    private function enlaceVigente(string $token): PruebaEnlace
    {
        $enlace = PruebaEnlace::withoutGlobalScopes()
            ->where('token', $token)->where('activo', true)
            ->with(['prueba' => fn ($q) => $q->withoutGlobalScopes()])
            ->first();
        if (! $enlace || $enlace->prueba?->estado !== Prueba::PUBLICADA) {
            throw new NotFoundHttpException;
        }

        return $enlace;
    }

    /**
     * @param  array{email: string, nombre: ?string, sub: string}  $persona
     */
    private function asignacionDe(PruebaEnlace $enlace, array $persona): PruebaAsignacion
    {
        $buscar = fn () => PruebaAsignacion::withoutGlobalScopes()
            ->where('enlace_id', $enlace->id)->where('participante_email', $persona['email'])->first();

        if ($existente = $buscar()) {
            return $existente;
        }

        try {
            return PruebaAsignacion::withoutGlobalScopes()->create([
                'empresa_id' => $enlace->empresa_id,
                'postulacion_id' => null,
                'prueba_id' => $enlace->prueba_id,
                'enlace_id' => $enlace->id,
                'participante_email' => $persona['email'],
                'participante_nombre' => $persona['nombre'],
                'participante_google_sub' => $persona['sub'],
                'token_hash' => hash('sha256', Str::random(64)),
                'disponible_hasta' => now()->addDays((int) config('reclutamiento.pruebas.dias_para_iniciar', 5))->endOfDay(),
                'estado' => PruebaAsignacion::PENDIENTE,
                'asignado_por' => $enlace->creado_por,
            ]);
        } catch (QueryException) {
            // Doble clic / dos pestañas a la vez: ganó la otra petición.
            if ($existente = $buscar()) {
                return $existente;
            }
            throw ValidationException::withMessages(['credencial' => 'No se pudo registrar tu participación. Inténtalo de nuevo.']);
        }
    }

    private function url(PruebaEnlace $enlace): string
    {
        return $this->correo->urlPortal("/empleos/evaluacion/{$enlace->token}");
    }
}
