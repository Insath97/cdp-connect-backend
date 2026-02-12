<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // This is a stub. Real implementation should be moved or aliased.
        return response()->json(['message' => 'Admin Login Stub'], 200);
    }

    public function logout(Request $request)
    {
        return response()->json(['message' => 'Admin Logout Stub'], 200);
    }

    public function me()
    {
        return response()->json(['message' => 'Admin Me Stub'], 200);
    }
}
