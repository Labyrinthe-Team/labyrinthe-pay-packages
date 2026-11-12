<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 422 `INVALID_PHONE_NUMBER`: the number is not valid in its country.
 */
class InvalidPhoneNumberException extends InvalidRequestException
{
}
