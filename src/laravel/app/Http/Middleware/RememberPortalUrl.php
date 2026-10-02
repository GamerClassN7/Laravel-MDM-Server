<?php

namespace App\Http\Middleware;

use App\Support\PortalUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the address signed-in users open the portal with, for the links of notifications the
 * scheduler sends (App\Support\PortalUrl). Not needed when APP_URL is set.
 */
class RememberPortalUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethod('GET') && ! $request->ajax() && $request->user() !== null && ! PortalUrl::configured()) {
            PortalUrl::remember(url('/'));
        }

        return $response;
    }
}
