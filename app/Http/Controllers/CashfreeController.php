<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CashfreeVerificationService;
use App\Models\VerificationLog;
use App\Models\OrgUsersMaster;
use App\Models\Company;

class CashfreeController extends Controller
{
    private $service;

    public function __construct(CashfreeVerificationService $service)
    {
        $this->service = $service;
        
    }



public function verify(Request $request)
{
    
    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */
    $request->validate([
        'type' => 'required|in:pan,gst,bank',
        'org_code' => 'required|string',
        'reference_id' => 'required|string',

        'pan' => 'required_if:type,pan',
        'gst' => 'required_if:type,gst',
        'account_number' => 'required_if:type,bank',
        'ifsc' => 'required_if:type,bank',
    ]);



    /*
    |--------------------------------------------------------------------------
    | FETCH DATA
    |--------------------------------------------------------------------------
    */
    $org = OrgUsersMaster::where('org_code', $request->org_code)->first();
    $company = Company::where('company_code', $request->org_code)->first();

    if (!$org) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid org_code'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | 🔥 ALREADY VERIFIED CHECK (NO API CALL)
    |--------------------------------------------------------------------------
    */
    if ($request->type === 'gst') {
        if ($org->gst_verified && $org->gst == $request->gst) {
            return response()->json([
                'status' => true,
                'message' => 'Verification successful',
                'data' => $org->gst_json
            ]);
        }
    }

    if ($request->type === 'pan') {
        if ($org->pan_verified && $org->pan == $request->pan) {
            return response()->json([
                'status' => true,
                'message' => 'Verification successful',
                'data' => $org->pan_json
            ]);
        }
    }

    if ($request->type === 'bank') {
        if (
            $org->bank_verified &&
            $org->account_number == $request->account_number &&
            $org->ifsc == $request->ifsc
        ) {
            return response()->json([
                'status' => true,
                'message' => 'Verification successful',
                'data' => $org->bank_json
            ]);
        }
    }

    DB::beginTransaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | BUILD PAYLOAD + CALL API
        |--------------------------------------------------------------------------
        */
        $payload = $this->buildPayload($request);
        
        $response = $this->callService($request->type, $payload);



        /*
        |--------------------------------------------------------------------------
        | SUCCESS CHECK
        |--------------------------------------------------------------------------
        */
        $isSuccess = $this->checkSuccess($response);

        /*
        |--------------------------------------------------------------------------
        | UPDATE DATA (ORG + COMPANY)
        |--------------------------------------------------------------------------
        */
        $org->updateVerificationData($request->type, $response);

        if ($company) {
            $company->updateVerificationData($request->type,$request->pan,  $response);
        }

        /*
        |--------------------------------------------------------------------------
        | LOGGING
        |--------------------------------------------------------------------------
        */
        $this->logVerification($request, $payload, $response, $isSuccess);

        DB::commit();

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'status' => $isSuccess,
            'message' => $isSuccess ? 'Verification successful' : 'Verification failed',
            'data' => $response
        ]);

    } catch (\Throwable $e) {

        DB::rollBack();

        $this->logError($request, $e);

        return response()->json([
            'status' => false,
            'message' => 'Verification failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /*
    |--------------------------------------------------------------------------
    | BUILD PAYLOAD
    |--------------------------------------------------------------------------
    */
    private function buildPayload($request)
    {
        switch ($request->type) {

            case 'pan':
                return [
                    "pan" => trim($request->pan),
                    "verification_id" => trim($request->reference_id),
                    "name" => trim($request->name)
                ];

            case 'gst':
                return [
                    "gst" => trim($request->gst),
                    "business_name" => trim($request->business_name)
                ];

            case 'bank':
                return [
                    "account_number" => trim($request->account_number),
                    "ifsc" => trim($request->ifsc),
                    "name" => trim($request->name),
                    "phone" => trim($request->phone)
                ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CALL SERVICE
    |--------------------------------------------------------------------------
    */
    private function callService($type, $payload)
    {
        switch ($type) {

            case 'pan':
                return $this->service->verifyPAN($payload);

            case 'gst':
                return $this->service->verifyGST($payload);

            case 'bank':
                return $this->service->verifyBank($payload);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS CHECK
    |--------------------------------------------------------------------------
    */
private function checkSuccess($response)
{
    /*
    |--------------------------------------------------------------------------
    | SERVICE WRAPPER
    |--------------------------------------------------------------------------
    */
    $data = $response['data'] ?? $response;

    /*
    |--------------------------------------------------------------------------
    | GST
    |--------------------------------------------------------------------------
    */
    if (isset($data['valid'])) {
        return $data['valid'] === true;
    }

    /*
    |--------------------------------------------------------------------------
    | PAN
    |--------------------------------------------------------------------------
    */
    if (isset($data['status'])) {

        $status = strtoupper((string) $data['status']);

        if (in_array($status, [
            'SUCCESS',
            'VERIFIED',
            'VALID'
        ])) {
            return true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BANK
    |--------------------------------------------------------------------------
    */
    if (isset($data['account_status'])) {

        $accountStatus = strtoupper((string) $data['account_status']);

        if (in_array($accountStatus, [
            'VALID',
            'SUCCESS',
            'VERIFIED'
        ])) {
            return true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FALLBACK
    |--------------------------------------------------------------------------
    */
    if (isset($data['sub_code'])) {

        return strtoupper($data['sub_code']) === 'SUCCESS';
    }

    return false;
}
    /*
    |--------------------------------------------------------------------------
    | LOG SUCCESS / FAILURE
    |--------------------------------------------------------------------------
    */
    private function logVerification($request, $payload, $response, $isSuccess)
    {
        VerificationLog::create([
            'org_code' => $request->org_code,
            'reference_id' => $request->reference_id,
            'type' => $request->type,
            'request_payload' => $payload,
            'response_payload' => $response,
            'status' => $isSuccess,
            'message' => $response['message'] ?? ($response['error'] ?? null)
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */
    private function logError($request, $e)
    {
        VerificationLog::create([
            'org_code' => $request->org_code,
            'reference_id' => $request->reference_id,
            'type' => $request->type,
            'request_payload' => $request->all(),
            'response_payload' => ['error' => $e->getMessage()],
            'status' => false,
            'message' => $e->getMessage()
        ]);
    }
    
    public function verifyGlobal(Request $request)
    {

        $request->validate([
            'type' => 'required|in:pan,gst,bank',
            'org_code' => 'required|string',
            'reference_id' => 'required|string',
    
            'pan' => 'required_if:type,pan',
            'gst' => 'required_if:type,gst',
            'account_number' => 'required_if:type,bank',
            'ifsc' => 'required_if:type,bank',
        ]);
    
    
       
        if ($request->type === 'gst') {
           
                return response()->json([
                    'status' => true,
                    'message' => 'Verification successful',
                ]);
            
        }
    
        if ($request->type === 'pan') 
        {
                return response()->json([
                    'status' => true,
                    'message' => 'Verification successful',
                ]);
            
        }
    
        if ($request->type === 'bank') {
            
                return response()->json([
                    'status' => true,
                    'message' => 'Verification successful',
                ]);
        }
    
        DB::beginTransaction();
    
        try {
   
            $payload = $this->buildPayload($request);
            
            $response = $this->callService($request->type, $payload);
    

            $isSuccess = $this->checkSuccess($response);
    
           
            return response()->json([
                'status' => $isSuccess,
                'message' => $isSuccess ? 'Verification successful' : 'Verification failed',
                'data' => $response
            ]);
    
        } catch (\Throwable $e) {
    
            DB::rollBack();
    
            $this->logError($request, $e);
    
            return response()->json([
                'status' => false,
                'message' => 'Verification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}