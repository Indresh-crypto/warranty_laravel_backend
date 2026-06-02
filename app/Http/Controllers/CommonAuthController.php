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
        'email'    => 'nullable|email',
        'phone'    => 'nullable|string'
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
    | REQUIRE EMAIL OR PHONE
    |--------------------------------------------------------------------------
    */
    if (!$request->filled('email') && !$request->filled('phone')) {
        return response()->json([
            'status'  => false,
            'message' => 'Email or phone is required'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | FIND COMPANY
    |--------------------------------------------------------------------------
    */
    $company = Company::with('leads')
        ->where(function ($query) use ($request) {

            if ($request->filled('email')) {
                $query->orWhere('contact_email', trim($request->email));
            }

            if ($request->filled('phone')) {
                $query->orWhere('contact_phone', trim($request->phone));
            }
        })
        ->first();

    /*
    |--------------------------------------------------------------------------
    | COMPANY LOGIN
    |--------------------------------------------------------------------------
    */
    if ($company) {

        // inactive account
        if ((int)$company->status === 0) {
            return response()->json([
                'status'  => false,
                'message' => 'Your account is inactive. Please contact support.'
            ], 403);
        }

        // password check
        if (!Hash::check($request->password, $company->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid password'
            ], 401);
        }

        // delete old tokens (optional)
        $company->tokens()->delete();

        // create token
        $token = $company->createToken('company_token')->plainTextToken;

        return response()->json([
            'status'  => true,
            'message' => 'Login successful',
            'type'    => 'company',
            'data'    => $company,
            'token'   => $token
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FIND EMPLOYEE
    |--------------------------------------------------------------------------
    */
    $employee = CompanyEmployee::where(function ($query) use ($request) {

            if ($request->filled('email')) {
                $query->orWhere('official_email', trim($request->email));
            }

            if ($request->filled('phone')) {

                $query->where(function ($subQ) use ($request) {

                    $subQ->orWhere('official_phone', trim($request->phone))
                         ->orWhere('personal_phone', trim($request->phone));
                });
            }
        })
        ->first();

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE NOT FOUND
    |--------------------------------------------------------------------------
    */
   if (!$employee) {
    return response()->json([
        'status'  => false,
        'message' => 'Invalid credentials or user not found'
    ], 404);
}

    
    $employee->location_id = "588149000012835244";

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE STATUS
    |--------------------------------------------------------------------------
    */
    if (isset($employee->status) && (int)$employee->status === 0) {
        return response()->json([
            'status'  => false,
            'message' => 'Your account is inactive. Please contact support.'
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | PASSWORD CHECK
    |--------------------------------------------------------------------------
    */
    if (!Hash::check($request->password, $employee->password)) {
        return response()->json([
            'status'  => false,
            'message' => 'Invalid password'
        ], 401);
    }

    /*
    |--------------------------------------------------------------------------
    | OPTIONAL ROLE
    |--------------------------------------------------------------------------
    */
    $employee->role = 3;

    /*
    |--------------------------------------------------------------------------
    | DELETE OLD TOKENS (OPTIONAL)
    |--------------------------------------------------------------------------
    */
    $employee->tokens()->delete();

    /*
    |--------------------------------------------------------------------------
    | CREATE TOKEN
    |--------------------------------------------------------------------------
    */
    $token = $employee->createToken('employee_token')->plainTextToken;

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */
    return response()->json([
        'status'  => true,
        'message' => 'Login successful',
        'type'    => 'company_employee',
        'data'    => $employee,
        'token'   => $token
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

    //  LOGIN ONLY (NO CREATE)
    $customer = WCustomer::where('email', $email)->first();

    if (!$customer) {
        return response()->json([
            'status' => false,
            'message' => 'Customer not registered. Please sign up first.'
        ], 404);
    }

    // Mark email verified if not already
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

    /*
    |--------------------------------------------------------------------------
    | FIND COMPANY EMPLOYEE IF COMPANY NOT FOUND
    |--------------------------------------------------------------------------
    */
    $employee = null;

    if (!$company) {
        $employee = CompanyEmployee::when($isEmail, function ($q) use ($loginValue) {
                $q->where('official_email', $loginValue);
            })
            ->when($isPhone, function ($q) use ($loginValue) {
                $q->where('official_phone', $loginValue)
                  ->orWhere('personal_phone', $loginValue);
            })
            ->first();
    }

    if (!$company && !$employee) {
        return response()->json([
            'status' => false,
            'message' => 'User not found'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE OTP
    |--------------------------------------------------------------------------
    */
    $otp = random_int(100000, 999999);

    if ($company) {
        $company->update([
            'otp' => $otp,
            'otp_expires_at' => now()->addMinutes(5)
        ]);
    }

    if ($employee) {
        $employee->update([
            'otp' => $otp,
            'otp_expires_at' => now()->addMinutes(5)
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SEND OTP TO PHONE (WhatsApp)
    |--------------------------------------------------------------------------
    */
    if ($isPhone) {

        Cache::put("otp_phone_{$loginValue}", $otp, now()->addMinutes(5));

        $destination = '91' . $loginValue;
        $apiKey = 'xmzzeoeowfppicbquvp3zupvntzeqh2j';
        $appName = 'WarrantyMitra';

        $template = json_encode([
            'id'     => '8d3e0965-dd63-4fce-a0aa-5e94aac810bc',
            'params' => [$otp],
        ]);

        Http::asForm()->withHeaders([
            'apikey' => $apiKey
        ])->post('https://api.gupshup.io/wa/api/v1/template/msg', [
            'channel'     => 'whatsapp',
            'source'      => '918828272570',
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

        $emailName = $company ? $company->business_name : $employee->full_name;
        $emailTo   = $company ? $company->contact_email : $employee->official_email;

        Mail::send('emails.company_otp', [
            'name' => $emailName,
            'otp'  => $otp
        ], function ($mail) use ($emailTo) {
            $mail->to($emailTo)
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
        'otp'         => 'required|digits:6'
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
            'status'  => false,
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

    /*
    |--------------------------------------------------------------------------
    | FIND EMPLOYEE IF COMPANY NOT FOUND
    |--------------------------------------------------------------------------
    */
    $employee = null;

    if (!$company) {

        $employee = CompanyEmployee::when($isEmail, function ($q) use ($loginValue) {

                $q->where('official_email', $loginValue);

            })
            ->when($isPhone, function ($q) use ($loginValue) {

                $q->where(function ($subQ) use ($loginValue) {

                    $subQ->where('official_phone', $loginValue)
                         ->orWhere('personal_phone', $loginValue);
                });

            })
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | USER NOT FOUND
    |--------------------------------------------------------------------------
    */
    if (!$company && !$employee) {

        return response()->json([
            'status'  => false,
            'message' => 'User not found'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | SELECT RECORD
    |--------------------------------------------------------------------------
    */
    $record = $company ?: $employee;

    /*
    |--------------------------------------------------------------------------
    | CHECK ACCOUNT STATUS
    |--------------------------------------------------------------------------
    */
    if (isset($record->status) && (int)$record->status === 0) {

        return response()->json([
            'status'  => false,
            'message' => 'Your account is inactive. Please contact support.'
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY OTP
    |--------------------------------------------------------------------------
    */
    $dbOtp      = trim((string) $record->otp);
    $requestOtp = trim((string) $request->otp);

    if ($dbOtp !== $requestOtp) {

        return response()->json([
            'status'  => false,
            'message' => 'Invalid OTP'
        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK OTP EXPIRY
    |--------------------------------------------------------------------------
    */
    if (
        !$record->otp_expires_at ||
        now()->greaterThan(Carbon::parse($record->otp_expires_at))
    ) {

        return response()->json([
            'status'  => false,
            'message' => 'OTP expired'
        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE VERIFICATION STATUS
    |--------------------------------------------------------------------------
    */
    $updateData = [
        'otp'            => null,
        'otp_expires_at' => null,
    ];

    if ($isEmail) {
        $updateData['is_email_verified'] = 1;
    }

    if ($isPhone) {
        $updateData['is_phone_verified'] = 1;
    }

    $record->update($updateData);

    /*
    |--------------------------------------------------------------------------
    | SET EMPLOYEE ROLE INSIDE DATA OBJECT
    |--------------------------------------------------------------------------
    */
    if (!$company) {
        $record->role = 3;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE OLD TOKENS
    |--------------------------------------------------------------------------
    */
    $record->tokens()->delete();

    /*
    |--------------------------------------------------------------------------
    | CREATE TOKEN
    |--------------------------------------------------------------------------
    */
    $token = $company
        ? $record->createToken('company_token')->plainTextToken
        : $record->createToken('employee_token')->plainTextToken;

    /*
    |--------------------------------------------------------------------------
    | REFRESH RECORD
    |--------------------------------------------------------------------------
    */
    $record->refresh();

    /*
    |--------------------------------------------------------------------------
    | ADD ROLE AGAIN AFTER REFRESH
    |--------------------------------------------------------------------------
    */
    if (!$company) {
        $record->role = 3;
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */
    return response()->json([
        'status'  => true,
        'message' => 'Verification successful',
        'type'    => $company ? 'company' : 'employee',
        'token'   => $token,
        'data'    => $record
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