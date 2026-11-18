<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Infrastructure\CorreoReclutamiento;
use App\Modules\Reclutamiento\Models\Prueba;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use App\Modules\Reclutamiento\Models\PruebaEnlace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enlace compartible de una prueba, sin cuenta ni login.
 *
 * - RR. HH. obtiene UN enlace por prueba y empresa (estable: se puede
 *   reenviar). Para pruebas del banco de Agento, el enlace queda en la
 *   empresa de quien lo genera y es ahí donde ve a los participantes.
 * - Quien lo abre declara nombre, correo y documento; se crea su asignación
 *   propia. Un correo = un único intento. Como el correo es declarado (no
 *   verificado), volver a registrarlo NO entrega el acceso de nuevo: quien
 *   ya ingresó retoma desde su mismo dispositivo, que conserva el token.
 * - Desde ahí la prueba se rinde con el flujo normal por token de asignación.
 */
class EnlacePruebaService
{
    public function __construct(
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
     * Registra al participante (datos declarados) y devuelve el token de SU
     * asignación, con el que rinde la prueba. El token solo se entrega al
     * crearla: un correo ya registrado no la recupera (evita que otra
     * persona retome o vea el intento de alguien más escribiendo su correo).
     *
     * @param  array{nombre: string, email: string, documento: string}  $datos
     * @return array{token: string, asignacion: PruebaAsignacion}
     */
    public function acceder(string $token, array $datos): array
    {
        $enlace = $this->enlaceVigente($token);
        $email = mb_strtolower(trim($datos['email']));
        $yaIngreso = ValidationException::withMessages(['email' => 'Este correo ya ingresó a la evaluación. Si necesitas retomarla, hazlo desde el mismo dispositivo y navegador, o pide ayuda al equipo de selección.']);

        $existe = fn () => PruebaAsignacion::withoutGlobalScopes()->where('enlace_id', $enlace->id)->where('participante_email', $email)->exists();
        if ($existe()) {
            throw $yaIngreso;
        }

        $acceso = Str::random(64);
        try {
            $asignacion = PruebaAsignacion::withoutGlobalScopes()->create([
                'empresa_id' => $enlace->empresa_id,
                'postulacion_id' => null,
                'prueba_id' => $enlace->prueba_id,
                'enlace_id' => $enlace->id,
                'participante_email' => $email,
                'participante_nombre' => trim($datos['nombre']),
                'participante_documento' => trim($datos['documento']),
                'token_hash' => hash('sha256', $acceso),
                'disponible_hasta' => now()->addDays((int) config('reclutamiento.pruebas.dias_para_iniciar', 5))->endOfDay(),
                'estado' => PruebaAsignacion::PENDIENTE,
                'asignado_por' => $enlace->creado_por,
            ]);
        } catch (QueryException $e) {
            // Doble clic / dos pestañas a la vez: ganó la otra petición.
            throw $existe() ? $yaIngreso : $e;
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

    private function url(PruebaEnlace $enlace): string
    {
        return $this->correo->urlPortal("/empleos/evaluacion/{$enlace->token}");
    }
}
