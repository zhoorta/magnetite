<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unless MAGNETITE_PUBLIC_SITE=true, the start page, the demo and the public documentation are off: a visitor who is
 * not signed in goes to the sign-in page, and a signed-in user who opens `/` goes to the library.
 */
class PublicSite
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('magnetite.public_site')) {
            return $next($request);
        }

        if ($request->user()) {
            return $request->is('/') ? redirect()->route('library') : $next($request);
        }

        return redirect()->route('login');
    }
}
