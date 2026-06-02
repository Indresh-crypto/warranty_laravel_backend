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
use App\Models\OrgUsersMaster;
use App\Models\WLead;
use App\Models\CandidateWhatsappMessage;


class WhatsappController extends Controller
{

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
    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    $validator = Validator::make($request->all(), [

        'contact_phone' =>
            'required|digits:10',

        'old_contact_phone' =>
            'nullable|digits:10',

        'company_id' =>
            'required|integer',
    ]);

    if ($validator->fails()) {

        return response()->json([

            'success' => false,

            'message' =>
                $validator->errors()->first(),

            'errors' =>
                $validator->errors()

        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD COMPANY
    |--------------------------------------------------------------------------
    */

    $company = Company::find(
        $request->company_id
    );

    if (!$company) {

        return response()->json([

            'success' => false,

            'message' =>
                'Company not found.'

        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | VARIABLES
    |--------------------------------------------------------------------------
    */

    $newPhone =
        trim($request->contact_phone);

    $oldPhone =
        trim($request->old_contact_phone);

    /*
    |--------------------------------------------------------------------------
    | PHONE UPDATE LOGIC
    |--------------------------------------------------------------------------
    */

    if (!empty($oldPhone)) {

        /*
        |--------------------------------------------------------------------------
        | VERIFY OLD PHONE
        |--------------------------------------------------------------------------
        */

        if (
            trim($company->contact_phone)
            != $oldPhone
        ) {

            return response()->json([

                'success' => false,

                'message' =>
                    'Old phone number does not match our records.'

            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE COMPANY PHONE
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use (
            $company,
            $oldPhone,
            $newPhone
        ) {

            $company->contact_phone =
                $newPhone;

            $company->save();

            /*
            |--------------------------------------------------------------------------
            | UPDATE WLEAD PHONE
            |--------------------------------------------------------------------------
            */

            WLead::where(
                    'phone',
                    $oldPhone
                )
                ->update([

                    'phone' =>
                        $newPhone
                ]);
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE OTP
    |--------------------------------------------------------------------------
    */

    $otp = rand(100000, 999999);

    /*
    |--------------------------------------------------------------------------
    | STORE OTP
    |--------------------------------------------------------------------------
    */

    Cache::put(

        'otp_' . $newPhone,

        $otp,

        now()->addMinutes(3)
    );

    /*
    |--------------------------------------------------------------------------
    | DESTINATION
    |--------------------------------------------------------------------------
    */

    $destination =
        '91' . $newPhone;

    /*
    |--------------------------------------------------------------------------
    | TEMPLATE
    |--------------------------------------------------------------------------
    */

    $template = [

        'id' =>
            '8d3e0965-dd63-4fce-a0aa-5e94aac810bc',

        'params' => [

            (string) $otp,

            (string) $otp
        ]
    ];

    try {

        /*
        |--------------------------------------------------------------------------
        | SEND WHATSAPP OTP
        |--------------------------------------------------------------------------
        */

        $response = Http::asForm()

            ->timeout(60)

            ->withHeaders([

                'apikey' =>
                    config('services.gupshup.key'),

                'Cache-Control' =>
                    'no-cache',

                'cache-control' =>
                    'no-cache',
            ])

            ->post(

                'https://api.gupshup.io/wa/api/v1/template/msg',

                [

                    'channel' =>
                        'whatsapp',

                    'source' =>
                        '918828272570',

                    'destination' =>
                        $destination,

                    'src.name' =>
                        'WarrantyMitra',

                    'template' =>
                        json_encode(
                            $template,
                            JSON_UNESCAPED_SLASHES
                        )
                ]
            );

        /*
        |--------------------------------------------------------------------------
        | RESPONSE BODY
        |--------------------------------------------------------------------------
        */

        $responseBody =
            $response->json();


        /*
        |--------------------------------------------------------------------------
        | FAILED RESPONSE
        |--------------------------------------------------------------------------
        */

        if (!$response->successful()) {

            return response()->json([

                'success' => false,

                'message' =>
                    'Failed to send OTP.',

                'error' =>
                    $responseBody

            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | SUCCESS RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'success' => true,

            'message' =>
                'OTP sent successfully.',

            'phone_updated' =>
                !empty($oldPhone),

            'updated_phone' =>
                $newPhone,

            /*
            |--------------------------------------------------------------------------
            | REMOVE OTP IN PRODUCTION
            |--------------------------------------------------------------------------
            */

            'otp' =>
                $otp
        ]);

    } catch (\Throwable $e) {

      

        return response()->json([

            'success' => false,

            'message' =>
                'Failed to send OTP.',

            'error' =>
                $e->getMessage()

        ], 500);
    }
}

   public function verifyOtp(Request $request)
    {
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

    $cachedOtp = Cache::get("otp_{$request->contact_phone}");

    if ($cachedOtp && $cachedOtp == $request->otp) {

        Cache::forget("otp_{$request->contact_phone}");

        // Update company
        Company::where('contact_phone', $request->contact_phone)
            ->update(['is_wa_verified' => 1]);

        // Update master
        OrgUsersMaster::where('phone', $request->contact_phone)
            ->update([
                'is_wa_verified' => 1
            ]);

        $user = Company::where('contact_phone', $request->contact_phone)->first();

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
                'source'       => '919372011028',
                'destination'  => $destinationPhoneNumber,
                'src.name'     => 'Goelectronix',
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
                        'source' => '919372011028',
                        'destination' => $destination,
                        'src.name' => 'Goelectronix',

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
    
    public function messages($phone)
    {

        $messages = CandidateWhatsappMessage::where('phone', $phone)

            ->orderBy('id', 'asc')
            ->get();

        return response()->json([

            'status' => true,
            'data' => $messages

        ]);

    }

    public function sendMessageCandidate(Request $request)

    {

        $request->validate([

            'phone' => 'required',

            'message' => 'required'

        ]);

        $phone = preg_replace('/[^0-9]/', '', $request->phone);

        try {

            $payload = [

                'channel' => 'whatsapp',

                'source' => '919372011028',

                'destination' => $phone,

                'src.name' => 'Goelectronix',

                'message' => json_encode([

                    'type' => 'text',

                    'text' => $request->message

                ])

            ];

            $response = Http::asForm()

                ->withHeaders([

                    'apikey' => env('GUPSHUP_API_KEY')

                ])

                ->post(

                    'https://api.gupshup.io/wa/api/v1/msg',

                    $payload

                );

            if (!$response->successful()) {

                return response()->json([

                    'status' => false,

                    'message' => 'Failed to send message',

                    'response' => $response->json()

                ], 500);

            }

            CandidateWhatsappMessage::create([

                'candidate_id' => $request->candidate_id,

                'phone' => $phone,

                'message' => $request->message,

                'direction' => 'sent',

                'message_type' => 'text',

                'status' => 'sent'

            ]);

            return response()->json([

                'status' => true,

                'message' => 'Message sent successfully'

            ]);

        } catch (\Exception $e) {

            return response()->json([

                'status' => false,

                'message' => $e->getMessage()

            ], 500);

        }

    }
}

