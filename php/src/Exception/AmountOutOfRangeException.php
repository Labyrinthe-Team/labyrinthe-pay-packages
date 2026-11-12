<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 422 `AMOUNT_TOO_SMALL`, `AMOUNT_TOO_LARGE` or `AMOUNT_OUT_OF_BOUNDS`: platform, country or provider limits. The message gives the limit that was crossed.
 */
class AmountOutOfRangeException extends InvalidRequestException
{
}
