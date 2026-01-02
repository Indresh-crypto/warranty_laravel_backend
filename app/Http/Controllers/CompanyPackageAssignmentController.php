<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\CompanyPackageAssignment;

class CompanyPackageAssignmentController extends Controller
{
    public function index()
    {
        return response()->json(
            CompanyPackageAssignment::with(['package.badge', 'badge'])->get(),
            200
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:200',
            'company_email' => 'nullable|email',
            'company_phone' => 'nullable|string|max:20',
            'package_id' => 'required|integer|exists:onboarding_packages,id',
            'badge_id' => 'required|integer|exists:w_badges,id',
            'start_date' => 'required|date',
            'expiry_date' => 'required|date|after_or_equal:start_date',
            'amount' => 'required|numeric',
            'benefits' => 'required',
            'eligibility' => 'required',
            'is_default'  => 'required',
            'company_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $assignment = CompanyPackageAssignment::create($validator->validated());
        return response()->json($assignment, 201);
    }

   
    public function show($companyId)
    {
        $assignments = CompanyPackageAssignment::with(['package.badge', 'badge', 'company'])
            ->where('company_id', $companyId)
            ->get();
    
        if ($assignments->isEmpty()) {
            return response()->json(['message' => 'No assigned packages found for this company'], 404);
        }
    
        return response()->json($assignments, 200);
    }
}