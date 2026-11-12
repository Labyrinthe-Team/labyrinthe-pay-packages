<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Http;

/**
 * Waits between two attempts. Inject a no-op implementation in tests.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
