<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * The API answered something the SDK cannot read (not JSON, missing field…).
 */
class UnexpectedValueException extends \UnexpectedValueException implements LabPayException
{
}
