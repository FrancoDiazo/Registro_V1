<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->role === 'worker' && $request->user()->is_active, 403);

        return $next($request);
    }
}
