<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ConfirmsPasswords;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ConfirmPasswordController extends Controller implements HasMiddleware
{
    use ConfirmsPasswords;

    protected $redirectTo = '/devices';

    public static function middleware(): array
    {
        return [
            new Middleware('auth'),
        ];
    }
}
