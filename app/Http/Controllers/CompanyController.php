<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class CompanyController extends Controller
{
    /**
     * Add a company
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'company_name' => 'required|string|max:255',
            'contact_phone' => 'required|string|unique:companies,contact_phone',
            'contact_email' => 'required|email|unique:companies,contact_email',
            'password' => 'required|min:6',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'=>false,
                'message'=>'Validation error',
                'errors'=>$validator->errors()
            ],422);
        }

        $company = Company::create([
            'company_name' => $request->company_name,
            'contact_person' => $request->contact_person,
            'contact_phone' => $request->contact_phone,
            'contact_email' => $request->contact_email,
            'password' => Hash::make($request->password),

            'address_line1' => $request->address_line1,
            'address_line2' => $request->address_line2,
            'city'          => $request->city,
            'state'         => $request->state,
            'district'      => $request->district,
            'pincode'       => $request->pincode,
            'pan'           => $request->pan,
            'gst'           => $request->gst,
            'business_type' => $request->business_type,
            'color'         => $request->color,
            'favicon'       => $request->favicon,
            'title'         => $request->title,
            'status' => 1
        ]);

        return response()->json([
            'status'=>true,
            'message'=>'Company created successfully',
            'data'=>$company
        ],201);
    }

    /**
     * Company Login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'contact_email' => 'required_without:contact_phone',
            'contact_phone' => 'required_without:contact_email',
            'password'=>'required'
        ]);

        if($validator->fails()){
            return response()->json([
                'status'=>false,
                'message'=>'Validation error',
                'errors'=>$validator->errors()
            ],422);
        }

        $company = Company::where('contact_email',$request->contact_email)
                   ->orWhere('contact_phone',$request->contact_phone)
                   ->first();

        if(!$company || !Hash::check($request->password,$company->password)){
            return response()->json([
                'status'=>false,
                'message'=>'Invalid credentials'
            ],401);
        }

        return response()->json([
            'status'=>true,
            'message'=>'Login successful',
            'data'=>$company
        ]);
    }

    /**
     * Get single company details
     */
    public function getCompany($id)
    {
        $company = Company::find($id);

        if(!$company){
            return response()->json([
                'status'=>false,
                'message'=>'Company not found'
            ],404);
        }

        return response()->json([
            'status'=>true,
            'message'=>'Company details',
            'data'=>$company
        ]);
    }

    /**
     * List all companies
     */
     public function list(Request $request)
    {
        $perPage = $request->per_page ?? 10;
    
        $companies = Company::query()
    
            ->when($request->business_name, function ($q) use ($request) {
                $q->where('business_name', 'like', '%' . $request->business_name . '%');
            })
    
            ->when($request->id, function ($q) use ($request) {
                $q->where('id', $request->id);
            })
    
            // STATE (comma separated)
            ->when($request->state, function ($q) use ($request) {
                $states = array_map('trim', explode(',', $request->state));
                $q->whereIn('state', $states);
            })
    
            // DISTRICT (comma separated)
            ->when($request->district, function ($q) use ($request) {
                $districts = array_map('trim', explode(',', $request->district));
                $q->whereIn('district', $districts);
            })
    
            // PINCODE (comma separated)
            ->when($request->pincode, function ($q) use ($request) {
                $pincodes = array_map('trim', explode(',', $request->pincode));
                $q->whereIn('pincode', $pincodes);
            })
    
            ->when($request->city, function ($q) use ($request) {
                $q->where('city', $request->city);
            })
    
            ->when($request->role, function ($q) use ($request) {
                $q->where('role', $request->role);
            })
    
            ->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->domain, function ($q) use ($request) {
                $q->where('domain', $request->domain);
            })
    
            ->when($request->is_verified, function ($q) use ($request) {
                $q->where('is_verified', $request->is_verified);
            })
    
            ->when($request->company_code, function ($q) use ($request) {
                $q->where('company_code', $request->company_code);
            })
    
            ->when($request->user_type, function ($q) use ($request) {
                $q->where('user_type', $request->user_type);
            })
    
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    
        return response()->json([
            'status'  => true,
            'message' => 'Companies list',
            'pagination' => [
                'current_page' => $companies->currentPage(),
                'per_page'     => $companies->perPage(),
                'last_page'    => $companies->lastPage(),
                'total'        => $companies->total(),
            ],
            'data' => $companies->items()
        ]);
    }
    /**
     * Activate / Deactivate Company
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(),[
            'status'=>'required|in:0,1'
        ]);

        if($validator->fails()){
            return response()->json([
                'status'=>false,
                'message'=>'Validation error',
                'errors'=>$validator->errors()
            ],422);
        }

        $company = Company::find($id);

        if(!$company){
            return response()->json([
                'status'=>false,
                'message'=>'Company not found'
            ],404);
        }

        $company->status = $request->status;
        $company->save();

        return response()->json([
            'status'=>true,
            'message'=>'Status updated successfully',
            'data'=>$company
        ]);
    }
    
    public function byPincode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pincode' => 'required|digits:6'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $retailers = Company::where('role', 5)
            ->where('pincode', $request->pincode)
            ->where('status', 1)
            ->select(
                'id',
                'business_name',
                'address_line1',
                'address_line2',
                'city',
                'state',
                'district',
                'pincode',
                'contact_phone'
            )
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $retailers
        ]);
    }
   public function update(Request $request, $id)
   {
    $company = Company::find($id);

    if (!$company) {
        return response()->json([
            'status' => false,
            'message' => 'Company not found'
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'company_name'   => 'sometimes|required|string|max:255',
        'contact_phone'  => 'sometimes|required|string|unique:companies,contact_phone,' . $company->id,
        'contact_email'  => 'sometimes|required|email|unique:companies,contact_email,' . $company->id,
        'password'       => 'sometimes|required|min:6',

        'contact_person' => 'sometimes|nullable|string|max:255',
        'address_line1'  => 'sometimes|nullable|string',
        'address_line2'  => 'sometimes|nullable|string',
        'city'           => 'sometimes|nullable|string',
        'state'          => 'sometimes|nullable|string',
        'district'       => 'sometimes|nullable|string',
        'pincode'        => 'sometimes|nullable|string',
        'pan'            => 'sometimes|nullable|string',
        'gst'            => 'sometimes|nullable|string',
        'business_type'  => 'sometimes|nullable|string',
        'color'          => 'sometimes|nullable|string',
        'favicon'        => 'sometimes|nullable|string',
        'title'          => 'sometimes|nullable|string',
        'status'         => 'sometimes|in:0,1',
        'is_password_changed' => 'sometimes|nullable'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors()
        ], 422);
    }

    /**
     * Update only non-null request values
     */
    $data = $request->only([
        'company_name',
        'contact_person',
        'contact_phone',
        'contact_email',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'district',
        'pincode',
        'pan',
        'gst',
        'business_type',
        'color',
        'favicon',
        'title',
        'status',
        'is_password_changed'
    ]);

    // Remove null values
    $data = array_filter($data, fn ($value) => !is_null($value));

    // Handle password separately
    if ($request->filled('password')) {
        $data['password'] = Hash::make($request->password);
    }

    $company->update($data);

    return response()->json([
        'status' => true,
        'message' => 'Company updated successfully',
        'data' => $company->fresh()
    ], 200);
}
}