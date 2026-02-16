<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CustomerRemark;
use Auth;
use DataTables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Maatwebsite\Excel\Facades\Excel;
use DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use App\Models\Company;
use App\Models\WDevice;
use App\Models\WCustomer;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;


class WhatsappController extends Controller
{

    public function sendWarrantyTest(Request $request)
    {
      
        $request->validate([
            'device_id' => 'required|exists:w_devices,id'
        ]);

        $device = WDevice::with('customer')->find($request->device_id);

        if (!$device || !$device->customer || empty($device->customer->mobile)) {
            return response()->json([
                'status' => false,
                'message' => 'Customer mobile missing'
            ], 400);
        }

        if (empty($device->certificate_link)) {
            return response()->json([
                'status' => false,
                'message' => 'Certificate link missing'
            ], 400);
        }

        $customer = $device->customer;

        $destination = '91' . ltrim($customer->mobile, '0');

        $companyDetails = Company::find($device->company_id);
        $companyName = $companyDetails->business_name ?? 'Goelectronix';

        try {
            $client = new Client();

            $response = $client->post(
                'https://api.gupshup.io/wa/api/v1/template/msg',
                [
                    'headers' => [
                        'apikey' => config('services.gupshup.key'),
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params' => [
                        'channel' => 'whatsapp',
                        'source' => '15557661628',
                        'destination' => '919039128100',
                        'src.name' => 'GoelectronixWarranty',

                        'template' => json_encode([
                            'id' => '7daef5bb-b87c-41e8-a646-b179277da272',
                            'params' => [
                                $customer->name,
                                $device->brand_name,
                                $device->model,
                                $device->imei1 ?? $device->serial,
                                $device->product_name,
                                $device->expiry_date,
                                $device->category_name,
                                "+919372011028",
                                "hello@goelectronix.com",
                                $companyName
                            ],
                        ]),

                        'message' => json_encode([
                            'type' => 'document',
                            'document' => [
                                'link' => $device->certificate_link,
                                'filename' => 'Warranty_' . $device->w_code . '.pdf',
                            ],
                        ]),
                    ],
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp Sent Successfully',
                'gupshup_response' => json_decode($response->getBody(), true)
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'WhatsApp Failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    private function optInUser($apiKey, $appName, $phone)
    {
        $response = Http::withHeaders([
            'apikey' => $apiKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->post("https://api.gupshup.io/sm/api/v1/app/opt/in/{$appName}", [
            'user' => $phone,
        ]);

        
        if ($response->successful()) {
            sleep(3); 
            return true;
        }
        return false;
    }

    private function sendMessage($apiKey, $templateid, $title, $salary, $jobtitle, $department, $openings, $location, $link, $phone)
    {
        $source = '918369719004';
        $phone = '91' . $phone; 
        
        $templateData = [
            "id" => $templateid,
            "params" => [
              $title, $salary, $location, $jobtitle, $openings, $location
            ]
        ];
    
        $messageData = [
            "type" => "image",
            "image" => ["link" => $link]
        ];
        
        $response = Http::asForm()->withHeaders([
            'apikey' => $apiKey
        ])->post('https://api.gupshup.io/wa/api/v1/template/msg', [
            'channel' => 'whatsapp',
            'source' => $source,
            'destination' => $phone,
            'src.name' => 'Goexrt',
            'template' => json_encode($templateData),
            
        ]);
    
        return response()->json([
            'ApiResponse' => $response->json()
        ]);
    }
   
    public function sendOtp(Request $request)
{
    $validator = Validator::make($request->all(), [
        'contact_phone' => 'required|digits:10',
        'company_id'    => 'required|integer',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors()
        ], 422);
    }

    $user = Company::where('contact_phone', $request->contact_phone)
        ->where('id', $request->company_id)
        ->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'User not found. You need to sign up first.',
            'user_exists' => false,
        ], 404);
    }

    // 🔐 Generate OTP
    $otp = rand(100000, 999999);
    $phone = $request->contact_phone;

    Cache::put("otp_{$phone}", $otp, now()->addMinutes(3));

    $destination = '91' . $phone;

    // ✅ Gupshup values
    $apiKey  = config('services.gupshup.key');
    $source  = '15557661628';
    $appName = 'GoelectronixWarranty';

    // Template payload
    $template = json_encode([
        'id'     => '20d82dbd-0fcb-46b4-a574-9b69719ce49a',
        'params' => [$otp],
    ]);

    $response = Http::asForm()
        ->withHeaders(['apikey' => $apiKey])
        ->post('https://api.gupshup.io/wa/api/v1/template/msg', [
            'channel'     => 'whatsapp',
            'source'      => $source,
            'destination' => $destination,
            'src.name'    => $appName,
            'template'    => $template,
        ]);

    if ($response->successful()) {
        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully.',
            'user_exists' => true,
            'otp' => $otp, // ❌ REMOVE IN PRODUCTION
        ]);
    }

    return response()->json([
        'success' => false,
        'message' => 'Failed to send OTP.',
        'error' => $response->json(),
    ], 500);
}


    public function verifyOtp(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'contact_phone' => 'required|digits:10',
            'otp'           => 'required|digits:6'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors()
            ], 422);
        }
    
        // Retrieve OTP from cache
        $cachedOtp = Cache::get("otp_{$request->contact_phone}");
    
        if ($cachedOtp && $cachedOtp == $request->otp) {
    
            // OTP valid - delete OTP (optional one-time use)
            Cache::forget("otp_{$request->contact_phone}");
    
            // Update company verification status
            Company::where('contact_phone', $request->contact_phone)
                ->update(['is_wa_verified' => 1]);
    
            // Get updated user
            $user = Company::where('contact_phone', $request->contact_phone)
                            ->first();
    
            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully',
                'data'    => $user
            ]);
        }
    
        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired OTP',
        ], 401);
    }
    
    public function sendWhatsAppTemplate($destinationPhoneNumber)
    {
    $client = new Client();

    try {
        $response = $client->post('https://api.gupshup.io/wa/api/v1/template/msg', [
            'headers' => [
                'Cache-Control' => 'no-cache',
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'apikey'        => 'xmzzeoeowfppicbquvp3zupvntzeqh2j',
            ],
            'form_params' => [
                'channel'      => 'whatsapp',
                'source'       => '15557661628',
                'destination'  => $destinationPhoneNumber,
                'src.name'     => 'GoelectronixWarranty',
                'template'     => json_encode([
                    'id'     => 'fe2b2208-cb40-4156-8b5e-b9f94a8f0d97',
                    'params' => []
                ])
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true);

    } catch (RequestException $e) {
        return [
            'error' => true,
            'message' => $e->getMessage(),
            'response' => $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : null
        ];
    }
}
}

