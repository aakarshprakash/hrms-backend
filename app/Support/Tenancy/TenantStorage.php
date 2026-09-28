<?php

namespace App\Support\Tenancy;

/**
 * Every generated file lives under its tenant's own prefix, so tenants with
 * the same employee codes or months can never overwrite -- or be served --
 * each other's files. Paths already stored on records stay valid.
 */
final class TenantStorage
{
    public static function path(int $companyId, string $path): string
    {
        return "tenants/{$companyId}/" . ltrim($path, '/');
    }
}
