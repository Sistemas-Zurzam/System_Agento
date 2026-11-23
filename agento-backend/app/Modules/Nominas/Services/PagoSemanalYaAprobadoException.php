<?php

namespace App\Modules\Nominas\Services;

use RuntimeException;

/** Zazu reenvió una semana que Nóminas ya aprobó o pagó: se responde 409. */
class PagoSemanalYaAprobadoException extends RuntimeException {}
