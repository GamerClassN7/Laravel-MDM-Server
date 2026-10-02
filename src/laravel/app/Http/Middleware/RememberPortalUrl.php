<?php

namespace App\Http\Middleware;

use App\Support\PortalUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the address system admins open the portal with, for the links of notifications the
 * scheduler sends (App\Support\PortalUrl). Not needed when APP_URL is set.
 */
class RememberPortalUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Only system admins: any signed-in user could send another Host header with their session
        // and point the links of everyone's notifications elsewhere.
        if ($request->isMethod('GET') && ! $request->ajax() && $request->user()?->can('is-system-admin') && ! PortalUrl::configured()) {
            PortalUrl::remember(url('/'));
        }

        return $response;
    }
}
