<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches the user interface to the language of the user (App\Support\Locales). Only for web
 * requests: the configured APP_LOCALE (English) stays for logs, the scheduler and the queue.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before signing in (setup and login page) the language can be picked with ?lang=.
        if (! $request->user() && Locales::isValid($request->query('lang')) && $request->hasSession()) {
            $request->session()->put('locale', $request->query('lang'));
        }

        App::setLocale(Locales::forRequest($request));

        return $next($request);
    }
}
