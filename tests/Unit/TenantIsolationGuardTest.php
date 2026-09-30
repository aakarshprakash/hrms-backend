<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * withoutGlobalScopes() removes the tenant scope along with the branch
 * scope. Application code that only means to skip branch filtering must use
 * withoutGlobalScope(BranchScope::class); crossing tenants is done
 * explicitly with TenantContext::withoutScoping().
 */
class TenantIsolationGuardTest extends TestCase
{
    public function test_application_code_never_strips_all_global_scopes(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2) . '/app';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                $code = preg_replace('#(//|\*|/\*).*$#', '', $line);
                if (str_contains($code, 'withoutGlobalScopes(')) {
                    $offenders[] = str_replace($root, 'app', $file->getPathname()) . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Use withoutGlobalScope(BranchScope::class) or TenantContext::withoutScoping() instead.');
    }
}
