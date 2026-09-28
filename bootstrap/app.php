<?php

use App\Http\Middleware\ResolveTenant;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trustHosts(at: ['localhost']);
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'feature' => \App\Http\Middleware\EnsureFeature::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Tenant must be known before route-model binding runs, so bound
        // models ({employee}, {payslip}, ...) resolve through the tenant scope.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A cross-tenant write is always a bug or an attack: log it loudly,
        // but never tell the caller more than "forbidden".
        $exceptions->render(function (TenantViolationException $e, Request $request) {
            report($e);

            return response()->json(['message' => 'This action is not allowed.', 'code' => 'TENANT_VIOLATION'], 403);
        });
    })->create();
