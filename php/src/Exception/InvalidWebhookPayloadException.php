<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * The webhook body is correctly signed but is not a valid LabPay notification. Answer 400.
 */
class InvalidWebhookPayloadException extends \RuntimeException implements LabPayException
{
}
