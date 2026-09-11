<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPortalApiKey
{
    /**
     * Handle an incoming request and verify the X-Portal-Key header.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $portalKey = $request->header('X-Portal-Key') ?? $request->query('portal_key');
        $expectedKey = config('services.portal_api.key');

        if (!$expectedKey || $portalKey !== $expectedKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid or missing X-Portal-Key.'
            ], 401);
        }

        return $next($request);
    }
}
