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
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
    
            // 🚫 Company inactive
            if ((int) $company->status === 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Your account is inactive. Please contact support.'
                ], 403);
            }
    
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
                'data'    =>  $company
            ]);
        }
    
        /*
        |--------------------------------------------------------------------------
        | 2. TRY LOGIN AS COMPANY EMPLOYEE
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
    
        // Force role for frontend clarity
        $employee->role = 3;
    
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

public function sendCompanyOtp(Request $request)
{
    $request->validate([
        'login_value' => 'required'
    ]);

    $loginValue = trim($request->login_value);

    /*
    |--------------------------------------------------------------------------
    | DETECT EMAIL OR PHONE
    |--------------------------------------------------------------------------
    */
    $isEmail = filter_var($loginValue, FILTER_VALIDATE_EMAIL);

    // Indian mobile: 10 digits
    $isPhone = preg_match('/^[6-9]\d{9}$/', $loginValue);

    if (!$isEmail && !$isPhone) {
        return response()->json([
            'status' => false,
            'message' => 'Enter valid email or mobile number'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | FIND COMPANY
    |--------------------------------------------------------------------------
    */
    $company = Company::when($isEmail, function ($q) use ($loginValue) {
            $q->where('contact_email', $loginValue);
        })
        ->when($isPhone, function ($q) use ($loginValue) {
            $q->where('contact_phone', $loginValue);
        })
        ->first();

    if (!$company) {
        return response()->json([
            'status' => false,
            'message' => 'Company not found'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE OTP
    |--------------------------------------------------------------------------
    */
    $otp = random_int(100000, 999999);

    $company->update([
        'otp' => $otp,
        'otp_expires_at' => now()->addMinutes(5)
    ]);

    /*
    |--------------------------------------------------------------------------
    | SEND OTP TO PHONE (WhatsApp)
    |--------------------------------------------------------------------------
    */
    
    if ($isPhone) {

        Cache::put("otp_phone_{$loginValue}", $otp, now()->addMinutes(5));

        $destination = '91' . $loginValue;
        $apiKey = 'xmzzeoeowfppicbquvp3zupvntzeqh2j';
        $appName = 'Goelectronix';

        $template = json_encode([
            'id'     => '660d8484-af82-4b82-9d3a-6cdab7b1b9da',
            'params' => [$otp],
        ]);

        Http::asForm()->withHeaders([
            'apikey' => $apiKey
        ])->post('https://api.gupshup.io/wa/api/v1/template/msg', [
            'channel'     => 'whatsapp',
            'source'      => '919372011028',
            'destination' => $destination,
            'src.name'    => $appName,
            'template'    => $template,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SEND OTP TO EMAIL
    |--------------------------------------------------------------------------
    */
    if ($isEmail) {

        Mail::send('emails.company_otp', [
            'name' => $company->business_name,
            'otp'  => $otp
        ], function ($mail) use ($company) {
            $mail->to($company->contact_email)
                 ->subject('Your Login OTP');
        });
    }

    return response()->json([
        'status' => true,
        'message' => 'OTP sent successfully'
    ], 200);
}
   
public function verifyCompanyOtp(Request $request)
{
    $request->validate([
        'login_value' => 'required',
        'otp' => 'required|digits:6'
    ]);

    $loginValue = trim($request->login_value);

    /*
    |--------------------------------------------------------------------------
    | DETECT EMAIL OR PHONE
    |--------------------------------------------------------------------------
    */
    $isEmail = filter_var($loginValue, FILTER_VALIDATE_EMAIL);
    $isPhone = preg_match('/^[6-9]\d{9}$/', $loginValue);

    if (!$isEmail && !$isPhone) {
        return response()->json([
            'status' => false,
            'message' => 'Enter valid email or mobile number'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | FIND COMPANY
    |--------------------------------------------------------------------------
    */
    $company = Company::when($isEmail, function ($q) use ($loginValue) {
            $q->where('contact_email', $loginValue);
        })
        ->when($isPhone, function ($q) use ($loginValue) {
            $q->where('contact_phone', $loginValue);
        })
        ->first();

    if (!$company) {
        return response()->json([
            'status' => false,
            'message' => 'Company not found'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY OTP
    |--------------------------------------------------------------------------
    */

    // ❌ Invalid OTP
    if ($company->otp != $request->otp) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid OTP'
        ], 400);
    }

    // ❌ Expired OTP
    if (!$company->otp_expires_at || now()->greaterThan($company->otp_expires_at)) {
        return response()->json([
            'status' => false,
            'message' => 'OTP expired'
        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS — CLEAR OTP
    |--------------------------------------------------------------------------
    */
    $company->update([
        'otp' => null,
        'otp_expires_at' => null,
        'is_email_verified' => 1
    ]);

    return response()->json([
        'status' => true,
        'message' => 'Verification successful',
        'data' => $company
    ], 200);
}

  public function googleLoginCompany(Request $request)
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
    $customer = Company::where('contact_email', $email)->first();

    if (!$customer) {
        return response()->json([
            'status' => false,
            'message' => 'User not registered. Please sign up first.'
        ], 404);
    }

 

    return response()->json([
        'status' => true,
        'message' => 'Login successful',
        'data' => $customer
    ], 200);
}

    public function setMpin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'm_pin'      => 'required|digits:4'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
    
        $company = Company::find($request->company_id);
    
        $company->update([
            'm_pin' => $request->m_pin,
            'is_mpin_active' => 1
        ]);
    
        return response()->json([
            'status' => true,
            'message' => 'MPIN set successfully'
        ]);
    }
    
    public function loginWithMpin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'm_pin'      => 'required|digits:4'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }
    
        // 🔹 Get Company Directly
        $company = Company::with('leads')
            ->find($request->company_id);
    
        // 🚫 Inactive
        if ((int) $company->status === 0) {
            return response()->json([
                'status'  => false,
                'message' => 'Your account is inactive.'
            ], 403);
        }
    
        // 🚫 MPIN not active
        if (!$company->is_mpin_active) {
            return response()->json([
                'status'  => false,
                'message' => 'MPIN not activated'
            ], 403);
        }
    
        // 🚫 Wrong MPIN
        if ($request->m_pin != $company->m_pin) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid MPIN'
            ], 401);
        }
    
        return response()->json([
            'status'  => true,
            'message' => 'Login successful',
            'type'    => 'company',
            'data'    => $company
        ]);
    }
}