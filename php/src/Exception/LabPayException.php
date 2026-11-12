<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * Marker implemented by every exception thrown by the SDK.
 * Catch it to handle any LabPay failure in one place.
 */
interface LabPayException extends \Throwable
{
}
