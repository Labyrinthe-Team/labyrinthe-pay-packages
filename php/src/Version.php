<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay;

final class Version
{
    /** Version of this SDK (SemVer). */
    public const SDK = '0.1.0';

    /** Version of the LabPay API covered by this SDK (path prefix /V2). */
    public const API = 'V2';

    private function __construct()
    {
    }
}
