<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJvLink
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('internal.jvlink_token');
        if (! is_string($expected) || $expected === '') {
            return response()->json(['message' => 'Internal ingest is not configured.'], 503);
        }
        $actual = $request->bearerToken();
        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
