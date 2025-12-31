<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PaymentGatewayController extends Controller
{
    public function getGateways(Request $request)
    {
       $data = PaymentGateway::get();
        return response()->json([
            'data' => $data
        ], Response::HTTP_OK);
    }
}