<?php

namespace App\Modules\Integraciones\Application;

use RuntimeException;

/** El lote ya fue aprobado: el proveedor no puede reemplazarlo reenviándolo (HTTP 409). */
class LoteYaAprobadoException extends RuntimeException {}
