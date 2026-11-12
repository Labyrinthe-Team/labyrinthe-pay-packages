<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 422 without a payment: the request was refused before any payment was created (`INVALID_CURRENCY`, `UNSUPPORTED_COUNTRY`, `UNSUPPORTED_PROVIDER`, `INVALID_AMOUNT`…). A payment refused by the provider is not an exception: it is returned with the `failed` status.
 */
class InvalidRequestException extends ApiException
{
}
