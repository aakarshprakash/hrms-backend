<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when a write would cross tenant boundaries. Always a bug or an
 * attack -- never something to catch and continue from.
 */
class TenantViolationException extends RuntimeException
{
}
