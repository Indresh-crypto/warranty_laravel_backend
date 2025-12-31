<?php
namespace App\Http\Controllers;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\IndiaPincode
;
class IndiaPincodeController extends Controller
{
    public function getByPincode($pincode)
    {
        $data = IndiaPincode::where('pincode', $pincode)->first();


        if ($data) {
            return response()->json([
                'success' => true,
                'state' => $data->state,
                'district' => $data->district,
                'state_code' => $data->state_in,
                'district_code' => $data->district_in
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Pincode not found',
        ], 404);
    }
}