<?php

namespace App\Http\Controllers;

use SteelAnts\LaravelAuth\Traits\Authentication;

class AuthController extends Controller
{
    use Authentication;

    protected string $redirectTo = 'devices';

    // Self-registration is disabled, users are created by a system admin.
    public function register()
    {
        abort(404);
    }

    public function registerPost()
    {
        abort(404);
    }
}
