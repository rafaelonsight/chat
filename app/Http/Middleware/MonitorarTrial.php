<?php

namespace App\Http\Middleware;

use App\Services\MonitoramentoDeTrial;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MonitorarTrial
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        app(MonitoramentoDeTrial::class)->observar($request, $response->getStatusCode());

        return $response;
    }
}
