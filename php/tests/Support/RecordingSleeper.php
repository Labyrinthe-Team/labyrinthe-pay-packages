<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Tests\Support;

use Labyrinthe\LabPay\Http\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
