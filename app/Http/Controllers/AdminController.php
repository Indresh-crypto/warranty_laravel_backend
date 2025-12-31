<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
  
public function login(Request $request)
{
    $validator = Validator::make($request->all(),[
        'username' => 'required_without:email',
        'email' => 'required_without:username',
        'password' => 'required'
    ],[
        'username.required_without' => 'Username or email is required',
        'email.required_without' => 'Email or username is required',
        'password.required' => 'Password is required'
    ]);

    if($validator->fails()){
        return response()->json([
            'status'=>false,
            'message'=>'Validation error',
            'errors'=>$validator->errors()
        ],422);
    }

    $admin = Admin::where('username',$request->username)
                ->orWhere('email',$request->email)
                ->first();

    if(!$admin || !Hash::check($request->password,$admin->password)){
        return response()->json([
            'status'=>false,
            'message'=>'Invalid login credentials'
        ],401);
    }

    if($admin->status == 0){
        return response()->json([
            'status' => false,
            'message' => 'Admin account disabled'
        ], 403);
    }

    return response()->json([
        'status'=>true,
        'message'=>'Login successful',
        'data'=>[
            'id'            => $admin->id,
            'name'          => $admin->name,
            'username'      => $admin->username,
            'email'         => $admin->email,
            'status'        => $admin->status,
            'type'  => 'admin'   
        ]
    ]);
}
    /**
     * Get Admin Profile
     */
    public function profile($id)
    {
        $admin = Admin::find($id);

        if(!$admin){
            return response()->json([
                'status'=>false,
                'message'=>'Admin not found'
            ],404);
        }

        return response()->json([
            'status'=>true,
            'message'=>'Admin profile',
            'data'=>$admin
        ]);
    }
}