<?php

namespace App\Http\Controllers;

use App\Models\TaskUser;
use App\Models\Task;
use App\Models\TaskRemark;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

use Google\Client as GoogleClient;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;


class TaskAuthController extends Controller
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
        $company = TaskUser::when($isEmail, function ($q) use ($loginValue) {
                $q->where('email', $loginValue);
            })
            ->when($isPhone, function ($q) use ($loginValue) {
                $q->where('mobile', $loginValue);
            })
            ->first();
    
        if (!$company) {
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
    
        $company->update([
            'otp' => "123456",
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
                'name' => $company->name,
                'otp'  => $otp
            ], function ($mail) use ($company) {
                $mail->to($company->email)
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
        $company = TaskUser::when($isEmail, function ($q) use ($loginValue) {
                $q->where('email', $loginValue);
            })
            ->when($isPhone, function ($q) use ($loginValue) {
                $q->where('mobile', $loginValue);
            })
            ->first();
    
        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
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
    
    
    
    public function addUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'            => 'required|string|max:255',
            'position'        => 'nullable|string|max:255',
            'mobile'          => 'required|string|max:20|unique:task_users,mobile',
            'email'           => 'nullable|email|max:255|unique:task_users,email',
            'location'        => 'nullable|string|max:255',
            'pincode'         => 'nullable|string|max:10',
            'report_to'       => 'nullable|exists:task_users,id',
            'role'            => 'nullable|numeric',
            'company_id'      => 'required|integer',
            'status'          => 'nullable',
            'picture'         => 'nullable|string'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()
            ], 422);
        }
    
        // ✅ Auto Generate Employee Code
        $lastUser = TaskUser::orderBy('id', 'desc')->first();
        $nextId = $lastUser ? $lastUser->id + 1 : 1;
    
        $generatedCode = 'EMP' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
        // Example: EMP0001, EMP0002
    
        $data = $request->all();
        $data['code'] = $generatedCode;
        $data['status'] = $request->status ?? 1;
    
        $user = TaskUser::create($data);
    
        return response()->json([
            'status' => true,
            'message' => 'Task User Created Successfully',
            'data' => $user
        ], 201);
    }
    
    
    public function updateUser(Request $request, $id)
    {
    $user = TaskUser::find($id);

    if (!$user) {
        return response()->json([
            'status' => false,
            'message' => 'Task User Not Found'
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'name'            => 'sometimes|required|string|max:255',
        'position'        => 'nullable|string|max:255',
        'mobile'          => [
                                'sometimes',
                                'required',
                                'string',
                                'max:20',
                                Rule::unique('task_users')->ignore($id)
                             ],
        'email'           => [
                                'nullable',
                                'email',
                                'max:255',
                                Rule::unique('task_users')->ignore($id)
                             ],
        'location'        => 'nullable|string|max:255',
        'city'            => 'nullable|string|max:255',
        'state'           => 'nullable|string|max:255',
        'district'        => 'nullable|string|max:255',
        'pincode'         => 'nullable|string|max:10',
        'report_to'       => 'nullable|exists:task_users,id',
        'role'            => 'nullable|numeric',
        'otp'             => 'nullable|string|max:10',
        'otp_expires_at'  => 'nullable|date',
        'company_id'      => 'sometimes|required|integer',
        'status'          => 'nullable|boolean',
        'picture'         => 'nullable|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    // Prevent changing employee code manually
    $data = $request->except(['code']);

    $user->update($data);

    return response()->json([
        'status' => true,
        'message' => 'Task User Updated Successfully',
        'data' => $user
    ]);
}

public function uploadPicture(Request $request)
{
    $request->validate([
        'picture' => 'required|image|mimes:jpg,jpeg,png|max:2048'
    ]);

    // Generate unique filename
    $filename = time() . '_' . uniqid() . '.' . $request->file('picture')->getClientOriginalExtension();

    // Move directly to public/uploads folder
    $request->file('picture')->move(public_path('uploads'), $filename);

    return response()->json([
        'status' => true,
        'message' => 'Image Uploaded Successfully',
        'file_name' => $filename,
        'file_path' => 'uploads/' . $filename,
        'full_url' => asset('uploads/' . $filename)
    ]);
}
      
 public function listUsers(Request $request)
{
    $request->validate([
        'company_id' => 'nullable|integer',
        'search'     => 'nullable|string',
        'status'     => 'nullable|boolean',
        'per_page'   => 'nullable|integer|min:1|max:100'
    ]);

    $perPage = $request->per_page ?? 10;

    $query = TaskUser::query()
        ->withCount([
            // Total tasks assigned to user
            'assignedTasks as total_tasks',

            // Pending tasks
            'assignedTasks as pending_tasks' => function ($q) {
                $q->where('status', 1)->where('status', 2);;
            },

           

            // Completed tasks
            'assignedTasks as completed_tasks' => function ($q) {
                $q->where('status', 3);
            }
        ]);

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    if ($request->filled('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    if ($request->filled('report_to')) {
        $query->where('report_to', $request->report_to);
    }

    if ($request->filled('id')) {
        $query->where('id', $request->id);
    }

    if ($request->filled('role')) {
        $query->where('role', $request->role);
    }

    if ($request->filled('search')) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%$search%")
              ->orWhere('mobile', 'like', "%$search%")
              ->orWhere('email', 'like', "%$search%")
              ->orWhere('code', 'like', "%$search%");
        });
    }

    if ($request->has('status')) {
        $query->where('status', $request->status);
    }

    $users = $query->orderByDesc('id')->paginate($perPage);

    return response()->json([
        'status' => true,
        'message' => 'User List Retrieved Successfully',
        'data' => $users
    ]);
}
    
    public function changeStatus(Request $request, $id)
    {
        $user = TaskUser::find($id);
    
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Task User Not Found'
            ], 404);
        }
    
        $validator = Validator::make($request->all(), [
            'status' => 'required|boolean'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()
            ], 422);
        }
    
        $user->update([
            'status' => $request->status
        ]);
    
        return response()->json([
            'status' => true,
            'message' => $request->status 
                            ? 'Task User Activated Successfully'
                            : 'Task User Deactivated Successfully',
            'data' => [
                'id' => $user->id,
                'current_status' => $user->status
            ]
        ]);
    }
    
   public function getUserById($id)
{
    $user = TaskUser::with('manager:id,name,picture')->find($id);

    if (!$user) {
        return response()->json([
            'status' => false,
            'message' => 'Task User Not Found'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Task Statistics
    |--------------------------------------------------------------------------
    */

    $taskQuery = Task::where(function ($q) use ($id) {
        $q->where('employee_id', $id)
          ->orWhere('created_by', $id);
    });

    $totalTasks = (clone $taskQuery)->count();

    $pendingTasks = (clone $taskQuery)
        ->where('status', 1)
        ->count();

    $openTasks = (clone $taskQuery)
        ->where('status', 2)
        ->count();

    $completedTasks = (clone $taskQuery)
        ->where('status', 3)
        ->count();

    /*
    |--------------------------------------------------------------------------
    | Latest Tasks With Remarks
    |--------------------------------------------------------------------------
    */

    $recentTasks = Task::with([
        'employee:id,name,picture',
        'creator:id,name,picture',
        'remarks.user:id,name,picture'
    ])
    ->where(function ($q) use ($id) {
        $q->where('employee_id', $id)
          ->orWhere('created_by', $id);
    })
    ->latest()
    ->limit(10)
    ->get();

    return response()->json([
        'status' => true,
        'message' => 'User Retrieved Successfully',
        'data' => [
            'user' => $user,

            'task_stats' => [
                'total_tasks' => $totalTasks,
                'pending_tasks' => $pendingTasks,
                'open_tasks' => $openTasks,
                'completed_tasks' => $completedTasks
            ],

          
        ]
    ]);
}
}