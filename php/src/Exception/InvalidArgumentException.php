<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * Invalid input, detected by the SDK before any request.
 */
class InvalidArgumentException extends \InvalidArgumentException implements LabPayException
{
}
