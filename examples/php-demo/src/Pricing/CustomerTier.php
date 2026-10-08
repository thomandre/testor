<?php

declare(strict_types=1);

namespace App\Pricing;

enum CustomerTier
{
    case Standard;
    case Premium;
    case Vip;
}
