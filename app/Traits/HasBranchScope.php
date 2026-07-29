<?php

namespace App\Traits;

use App\Models\Scopes\BranchScope;

trait HasBranchScope
{
    public static function bootHasBranchScope(): void
    {
        static::addGlobalScope(new BranchScope());
    }
}
