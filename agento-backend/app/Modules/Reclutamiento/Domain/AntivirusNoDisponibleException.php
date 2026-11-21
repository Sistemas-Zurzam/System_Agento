<?php

namespace App\Modules\Reclutamiento\Domain;

use RuntimeException;

/**
 * El antivirus no respondió y la configuración no permite continuar sin él: se reintenta.
 */
class AntivirusNoDisponibleException extends RuntimeException {}
