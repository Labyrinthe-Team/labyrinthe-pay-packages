<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * The webhook signature is missing, wrong, or its timestamp is outside the tolerance. Answer 400.
 */
class SignatureVerificationException extends \RuntimeException implements LabPayException
{
}
