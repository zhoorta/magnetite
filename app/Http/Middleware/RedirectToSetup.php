<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** While no user exists, the pages a visitor lands on lead to the set-up form. */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->is('/', 'login', 'library') && ! User::query()->exists()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
