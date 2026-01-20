<?php

namespace App\Http\Controllers;

use App\Models\WBadge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class WBadgeController extends Controller
{
  
  public function index()
{
    $badges = WBadge::all()->map(function ($badge) {
        $badge->image_url = $badge->image 
            ? url('storage/' . $badge->image)
            : url('storage/default.png');  // optional default image
        return $badge;
    });

    return response()->json($badges, 200);
}

public function show($id)
{
    $badge = WBadge::find($id);
    if (!$badge) {
        return response()->json(['message' => 'Badge not found'], 404);
    }

    $badge->image_url = $badge->image
        ? url('storage/' . $badge->image)
        : url('storage/default.png');

    return response()->json($badge, 200);
}


    // CREATE
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:150',
            'eligibility' => 'required|integer',
            'description' => 'nullable|string',
            'benefits'    => 'nullable|numeric',
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'pay_now'   =>   'nullable|integer',
            'pay_later' =>   'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $validator->validated();

        // handle image upload
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('wbadges', 'public'); // storage/app/public/wbadges/...
            $data['image'] = $path;
        }

        $badge = WBadge::create($data);

        if ($badge->image) {
            $badge->image_url = asset('storage/' . $badge->image);
        }

        return response()->json($badge, 201);
    }

    // UPDATE
    public function update(Request $request, $id)
    {
        $badge = WBadge::find($id);
        if (!$badge) {
            return response()->json(['message' => 'Badge not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|string|max:150',
            'eligibility' => 'sometimes|integer',
            'description' => 'nullable|string',
            'benefits'    => 'nullable|numeric',
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'pay_now'   =>   'nullable|integer',
            'pay_later' =>   'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $validator->validated();

        // if new image uploaded, optionally delete old one
        if ($request->hasFile('image')) {
            if ($badge->image && Storage::disk('public')->exists($badge->image)) {
                Storage::disk('public')->delete($badge->image);
            }

            $path = $request->file('image')->store('wbadges', 'public');
            $data['image'] = $path;
        }

        $badge->update($data);

        if ($badge->image) {
            $badge->image_url = asset('storage/' . $badge->image);
        }

        return response()->json($badge, 200);
    }

    // DELETE
    public function destroy($id)
    {
        $badge = WBadge::find($id);
        if (!$badge) {
            return response()->json(['message' => 'Badge not found'], 404);
        }

        if ($badge->image && Storage::disk('public')->exists($badge->image)) {
            Storage::disk('public')->delete($badge->image);
        }

        $badge->delete();
        return response()->json(['message' => 'Badge deleted successfully'], 200);
    }
}