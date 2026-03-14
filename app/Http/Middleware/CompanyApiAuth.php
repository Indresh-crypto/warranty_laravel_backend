<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Company;

class CompanyApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('Authorization');

        if (!$token) {
            return response()->json([
                'status' => false,
                'message' => 'Token not provided'
            ], 401);
        }

        // Remove Bearer prefix if exists
        $token = str_replace('Bearer ', '', $token);

        $company = Company::where('api_token', $token)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token'
            ], 401);
        }

        if ($company->is_logout) {
            return response()->json([
                'status' => false,
                'message' => 'User logged out'
            ], 401);
        }

        // Attach company to request
        $request->merge(['auth_company' => $company]);

        return $next($request);
    }
}