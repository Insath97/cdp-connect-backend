<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check if user is authenticated via api guard
        if (!auth('api')->check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user = auth('api')->user();

        // 2. Check if user is a customer or has a linked customer profile
        $customer = \App\Models\Customer::where('customer_id', $user->id)
            ->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($user->id_number)])
            ->first();

        $isCustomer = ($user->user_type === 'customer') || ($customer !== null);

        if (!$isCustomer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden. Access restricted to customers only.',
            ], 403);
        }

        // 3. Check if customer profile is active
        if ($customer && !$customer->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden. Your customer account is inactive.',
            ], 403);
        }

        // 4. Check if user account is active and can login
        if (!$user->canLogin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden. Your account is inactive or disabled.',
            ], 403);
        }

        return $next($request);
    }
}
