<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 422 `VALIDATION_ERROR` (or 422 without code on invalid list filters):
 * per-field messages in getErrors().
 */
class ValidationException extends InvalidRequestException
{
}
