<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyEmployee;
use App\Models\Agent;
use App\Models\Retailer;
use App\Models\WLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

use Google\Client as GoogleClient;
use App\Models\WCustomer;
use Illuminate\Support\Str;

class CommonAuthController extends Controller
{

   public function login(Request $request)
   {
    $validator = Validator::make($request->all(), [
        'password' => 'required',
        'email'    => 'sometimes|email',
        'phone'    => 'sometimes|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors()
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. TRY LOGIN AS COMPANY
    |--------------------------------------------------------------------------
    */
    $company = Company::with('leads')
        ->where(function ($q) use ($request) {
            if ($request->email) {
                $q->where('contact_email', $request->email);
            }
            if ($request->phone) {
                $q->orWhere('contact_phone', $request->phone);
            }
        })
        ->first();

    if ($company) {
        if (!Hash::check($request->password, $company->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid password'
            ], 401);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Login successful',
            'type'    => 'company',
            'data'    => $company
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. TRY LOGIN AS COMPANY EMPLOYEE (IF COMPANY NOT FOUND)
    |--------------------------------------------------------------------------
    */
    $employee = CompanyEmployee::where(function ($q) use ($request) {
            if ($request->email) {
                $q->where('official_email', $request->email);
            }
            if ($request->phone) {
                $q->orWhere('personal_phone', $request->phone);
            }
        })
        ->first();

    if (!$employee) {
        return response()->json([
            'status'  => false,
            'message' => 'Invalid credentials or user not found'
        ], 404);
    }

    if (!Hash::check($request->password, $employee->password)) {
        return response()->json([
            'status'  => false,
            'message' => 'Invalid password'
        ], 401);
    }
    $employee->role=3;
    return response()->json([
        'status'  => true,
        'message' => 'Login successful',
        'type'    => 'company_employee',
        'data'    => $employee
    ]);
}
    private function generateCode($prefix, $model, $column)
    {
        $last = $model::orderBy('id','desc')->first();
        if (!$last) return $prefix . "0001";

        $num = intval(substr($last->$column, strlen($prefix))) + 1;
        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }


   public function googleLoginCustomer(Request $request)
  {
    $request->validate([
        'token' => 'required'
    ]);

    $client = new GoogleClient([
        'client_id' => config('services.google.client_id')
    ]);

    // ✅ Verify Google ID token
    $payload = $client->verifyIdToken($request->token);

    if (!$payload) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid Google token'
        ], 401);
    }

    $email = $payload['email'];

    // 🔍 LOGIN ONLY (NO CREATE)
    $customer = WCustomer::where('email', $email)->first();

    if (!$customer) {
        return response()->json([
            'status' => false,
            'message' => 'Customer not registered. Please sign up first.'
        ], 404);
    }

    // ✅ Mark email verified if not already
    if (!$customer->is_email_verified) {
        $customer->update([
            'is_email_verified' => 1
        ]);
    }

    return response()->json([
        'status' => true,
        'message' => 'Login successful',
        'data' => $customer->load(['addresses', 'devices', 'retailer'])
    ], 200);
}

public function logout(Request $request)
{
    $validator = Validator::make($request->all(), [
        'id'        => 'nullable|integer|exists:companies,id',
        'is_logout' => 'required|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation failed',
            'errors'  => $validator->errors()
        ], 422);
    }

    // ✅ CASE 1: Logout a specific user
    if ($request->filled('id')) {
        Company::where('id', $request->id)
            ->update(['is_logout' => $request->is_logout]);

        return response()->json([
            'status'  => true,
            'message' => 'User logout status updated successfully',
            'scope'   => 'single_user'
        ], 200);
    }

    // ✅ CASE 2: Logout all users
    Company::query()->update([
        'is_logout' => $request->is_logout
    ]);

    return response()->json([
        'status'  => true,
        'message' => 'Logout status updated for all users',
        'scope'   => 'all_users'
    ], 200);
}

public function getLogoutStatus($id)
{
    $user = Company::select('id', 'is_logout')->find($id);

    if (!$user) {
        return response()->json([
            'status'  => false,
            'message' => 'User not found'
        ], 404);
    }

    return response()->json([
        'status'    => true,
        'user_id'   => $user->id,
        'is_logout' => (bool) $user->is_logout
    ], 200);
}

}