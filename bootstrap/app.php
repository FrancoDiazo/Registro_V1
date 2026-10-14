<?php

use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\EnsureWorker;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'administrator' => EnsureAdministrator::class,
            'worker' => EnsureWorker::class,
        ]);
    })->create();
