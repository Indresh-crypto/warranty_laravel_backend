<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OnboardingPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OnboardingPackageController extends Controller
{
    public function index()
    {
        return response()->json(
            OnboardingPackage::with('badge')->get(),
            200
        );
    }

    public function show($id)
    {
        $package = OnboardingPackage::with('badge')->find($id);
        if (!$package) {
            return response()->json(['message' => 'Package not found'], 404);
        }
        return response()->json($package, 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'badge_id'      => 'required|integer|exists:w_badges,id',
            'package_name'  => 'required|string|max:150',
            'validity_days' => 'required|integer',
            'amount'        => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $package = OnboardingPackage::create($validator->validated());
        return response()->json($package, 201);
    }

    public function update(Request $request, $id)
    {
        $package = OnboardingPackage::find($id);
        if (!$package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'badge_id'      => 'sometimes|integer|exists:w_badges,id',
            'package_name'  => 'sometimes|string|max:150',
            'validity_days' => 'sometimes|integer',
            'amount'        => 'sometimes|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $package->update($validator->validated());
        return response()->json($package, 200);
    }

    public function destroy($id)
    {
        $package = OnboardingPackage::find($id);
        if (!$package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        $package->delete();
        return response()->json(['message' => 'Package deleted successfully'], 200);
    }
}