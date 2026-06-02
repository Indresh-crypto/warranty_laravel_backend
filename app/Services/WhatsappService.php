<?php

namespace App\Services;

use App\Models\TemplateImage;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\WDevice;
use App\Models\Company;
class WhatsappService
{
    protected $client;

    protected $baseUrl;

    protected $source;

    protected $appName;

    protected $apiKey;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 15,
            'http_errors' => true,
        ]);

        $this->baseUrl =
            'https://api.gupshup.io/wa/api/v1/template/msg';

        $this->source =
            env('GUPSHUP_WHATSAPP_NUMBER');

        $this->appName =
            env('GUPSHUP_APP_NAME');

        $this->apiKey =
            env('GUPSHUP_API_KEY');
    }

    /**
     * =====================================================
     * FORMAT MOBILE
     * =====================================================
     */

    protected function formatMobile($mobile)
    {
        return '91' . ltrim(
            preg_replace('/\D/', '', $mobile),
            '0'
        );
    }

    /**
     * =====================================================
     * SEND REQUEST TO GUPSHUP
     * =====================================================
     */

    protected function sendRequest(array $params)
    {
        try {

            // =============================================
            // REQUEST BODY
            // =============================================

            $payload = http_build_query($params);

            Log::info('GUPSHUP REQUEST', [
                'payload' => $payload
            ]);

            // =============================================
            // API REQUEST
            // =============================================

            $response = $this->client->request(
                'POST',
                $this->baseUrl,
                [

                    'headers' => [

                        'apikey' =>
                            $this->apiKey,

                        'Content-Type' =>
                            'application/x-www-form-urlencoded'
                    ],

                    'body' => $payload
                ]
            );

            // =============================================
            // RESPONSE
            // =============================================

            $responseBody = $response
                ->getBody()
                ->getContents();

            $decoded = json_decode(
                $responseBody,
                true
            );

            Log::info('GUPSHUP RESPONSE', [
                'response' => $decoded
            ]);

            return [

                'success' => true,

                'response' => $decoded
            ];

        } catch (\Throwable $e) {

            Log::error('GUPSHUP FAILED', [

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]);

            return [

                'success' => false,

                'message' =>
                    $e->getMessage()
            ];
        }
    }

    /**
     * =====================================================
     * PAYMENT SUCCESS WHATSAPP
     * =====================================================
     */

    public function paymentSuccessWhatsapp(
        $company,
        $zohopayment,
        $amount,
        $razorpayid,
        $note = null
    ) {

        try {

            // =============================================
            // VALIDATION
            // =============================================

            if (!$company) {

                throw new \Exception(
                    'Company not found'
                );
            }

            if (empty($company->contact_phone)) {

                throw new \Exception(
                    'Company mobile missing'
                );
            }

            // =============================================
            // DESTINATION
            // =============================================

            $destination = $this->formatMobile(
                $company->contact_phone
            );

            // =============================================
            // IMAGE
            // =============================================

            $imageUrl =
                'https://fss.gupshup.io/0/public/0/0/gupshup/918828272570/7e6171c6-09f9-4150-97a6-b2c6c015d3b1/1777987402451_Screenshot%202026-05-04%20at%207.39.46%C3%A2%C2%80%C2%AFPM.png';

            // =============================================
            // TEMPLATE JSON
            // =============================================

            $template = json_encode([

                'id' =>
                    'c3ed1ed9-3837-452c-811b-ff44674478d3',

                'params' => [

                    trim(
                        (
                            ($company->business_name ?? '') .
                            ' ' .
                            ($company->company_code ?? '')
                        )
                    ) ?: 'Retailer',

                    $zohopayment['payment_id']
                        ?? 'PAY',

                    $razorpayid
                        ?? 'pay',

                    number_format(
                        (float)($amount ?? 0),
                        2,
                        '.',
                        ''
                    ),

                    now()->format('d-m-Y'),

                    $note ??
                    'Payment received successfully'
                ]

            ], JSON_UNESCAPED_SLASHES);

            // =============================================
            // MESSAGE JSON
            // =============================================

            $message = json_encode([

                'type' => 'image',

                'image' => [

                    'link' =>
                        $imageUrl
                ]

            ], JSON_UNESCAPED_SLASHES);

            // =============================================
            // SEND REQUEST
            // =============================================

            return $this->sendRequest([

                'channel' =>
                    'whatsapp',

                'source' =>
                    $this->source,

                'destination' =>
                    $destination,

                'src.name' =>
                    $this->appName,

                'template' =>
                    $template,

                'message' =>
                    $message
            ]);

        } catch (\Throwable $e) {

            Log::error(
                'PAYMENT WHATSAPP FAILED',
                [

                    'company_id' =>
                        $company->id ?? null,

                    'message' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),

                    'file' =>
                        $e->getFile()
                ]
            );

            return [

                'success' => false,

                'message' =>
                    $e->getMessage()
            ];
        }
    }

    /**
     * =====================================================
     * INVOICE WHATSAPP
     * =====================================================
     */

    public function invoiceWhatsapp(
        $company,
        $invoiceNumber,
        $invoiceDate,
        $amount,
        $invoiceUrl
    ) {

        try {

            // =============================================
            // VALIDATION
            // =============================================

            if (!$company) {

                throw new \Exception(
                    'Company not found'
                );
            }

            if (empty($company->contact_phone)) {

                throw new \Exception(
                    'Company mobile missing'
                );
            }

            // =============================================
            // DESTINATION
            // =============================================

            $destination = $this->formatMobile(
                $company->contact_phone
            );

            // =============================================
            // TEMPLATE
            // =============================================

            $template = json_encode([

                'id' =>
                    'f6231387-e942-4dc9-b2ed-7d9e4bf430c4',

                'params' => [

                    trim(
                        (
                            ($company->business_name ?? '') .
                            ' ' .
                            ($company->company_code ?? '')
                        )
                    ) ?: 'Retailer',

                    $invoiceNumber ?? '-',

                    $invoiceDate
                        ? Carbon::parse($invoiceDate)
                            ->format('d-m-Y')
                        : now()->format('d-m-Y'),

                    number_format(
                        (float)($amount ?? 0),
                        2,
                        '.',
                        ''
                    ),

                    $invoiceUrl ?? '#'
                ]

            ], JSON_UNESCAPED_SLASHES);

            // =============================================
            // SEND REQUEST
            // =============================================

            return $this->sendRequest([

                'channel' =>
                    'whatsapp',

                'source' =>
                    $this->source,

                'destination' =>
                    $destination,

                'src.name' =>
                    $this->appName,

                'template' =>
                    $template
            ]);

        } catch (\Throwable $e) {

            Log::error(
                'INVOICE WHATSAPP FAILED',
                [

                    'company_id' =>
                        $company->id ?? null,

                    'message' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),

                    'file' =>
                        $e->getFile()
                ]
            );

            return [

                'success' => false,

                'message' =>
                    $e->getMessage()
            ];
        }
    }
    
    public function sendWarranty(WDevice $device): void
    {
    try {

        $device->load('customer');

        if (
            !$device->customer ||
            empty($device->customer->mobile)
        ) {

            throw new \Exception(
                'Customer mobile missing'
            );
        }

        $destination = $this->formatMobile(
            $device->customer->mobile
        );

        $company = Company::find(
            $device->company_id
        );

        $companyName =
            $company->business_name
            ?? 'WarrantyMitra';

        /*
        |--------------------------------------------------------------------------
        | TEMPLATE
        |--------------------------------------------------------------------------
        */

        $template = json_encode([

            'id' =>

                'e5f149fb-7044-461b-861d-dab3d0461bdf',

            'params' => [

                // 1 Customer Name
                $device->customer->name
                    ?? 'Customer',

                // 2 Brand
                $device->brand_name
                    ?? '-',

                // 3 Model
                $device->model
                    ?? '-',

                // 4 IMEI / Serial
                $device->imei1
                    ?? $device->serial
                    ?? '-',

                // 5 Category
                $device->category_name
                    ?? '-',

                // 6 Expiry Date
                !empty($device->expiry_date)
                    ? Carbon::parse(
                        $device->expiry_date
                    )->format('d-m-Y')
                    : '-',

                // 7 Warranty Product
                $device->product_name
                    ?? '-',

                // 8 Support Number
                '+918828272570',

                // 9 Company Name
                $companyName
            ]

        ], JSON_UNESCAPED_SLASHES);

        /*
        |--------------------------------------------------------------------------
        | DOCUMENT MESSAGE
        |--------------------------------------------------------------------------
        */

        $message = json_encode([

            'type' => 'document',

            'document' => [

                'link' =>

                    $device->certificate_link,

                'filename' =>

                    'Warranty_' .
                    ($device->w_code ?? 'CERT') .
                    '.pdf'
            ]

        ], JSON_UNESCAPED_SLASHES);

        /*
        |--------------------------------------------------------------------------
        | SEND REQUEST
        |--------------------------------------------------------------------------
        */

        $response = $this->sendRequest([

            'channel' => 'whatsapp',

            'source' => '918828272570',

            'destination' => $destination,

            'src.name' => 'WarrantyMitra',

            'template' => $template,

            'message' => $message
        ]);

        Log::info(
            'Warranty WhatsApp Sent',
            [

                'device_id' =>
                    $device->id,

                'destination' =>
                    $destination,

                'response' =>
                    $response
            ]
        );

    } catch (\Throwable $e) {

        Log::error(
            'Warranty WhatsApp Failed',
            [

                'device_id' =>
                    $device->id ?? null,

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );
    }
}


     public function sendWarrantyProvision(WDevice $device): void 
     {

        try {

            $device->load('customer');

            if (
                !$device->customer ||
                empty($device->customer->mobile)
            ) {
                throw new \Exception(
                    'Customer mobile missing'
                );
            }

            $destination = $this->formatMobile(
                $device->customer->mobile
            );

            $company = Company::find(
                $device->company_id
            );

            $companyName =
                $company->business_name ??
                'Goelectronix';

            // =============================================
            // TEMPLATE
            // =============================================

            $template = json_encode([

                'id' =>
                    'b7784344-7471-4e7b-aa14-a14b74a0ad71',

                'params' => [

                    $device->customer->name,

                    $device->brand_name,

                    $device->model,

                    $device->imei1 ??
                    $device->serial,

                    $device->category_name,

                    Carbon::parse(
                        $device->expiry_date
                    )->format('d-m-Y'),

                    $device->product_name,

                    '+919372011028',

                    'hello@goelectronix.com',

                    $companyName,

                    $device->customer->name,

                    'Device:',

                    $device->model,

                    'SR',

                    '📄',

                    'Provisional',

                    Carbon::parse(
                        $device->expiry_date
                    )->format('d-m-Y'),

                    'contact',

                    'anytime:',

                    'Email'
                ]

            ], JSON_UNESCAPED_SLASHES);

            // =============================================
            // SEND
            // =============================================

            $this->sendRequest([

                'channel' => 'whatsapp',

                'source' => $this->source,

                'destination' => $destination,

                'src.name' => $this->appName,

                'template' => $template
            ]);

        } catch (\Throwable $e) {

            Log::error(
                'Provisional Warranty WhatsApp Failed',
                [

                    'device_id' =>
                        $device->id ?? null,

                    'message' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),

                    'file' =>
                        $e->getFile()
                ]
            );
        }
    }
    
    //
    
     public function sendPaymentReceiptWhatsapp(

        $company,

        $arpId,

        $receiptId,

        $amount,

        $date = null,

        $closingBalance = null

      ) {

        try {

            // =============================================

            // VALIDATION

            // =============================================

            if (!$company) {

                throw new \Exception(

                    'Company not found'

                );

            }

            if (

                empty($company->contact_phone)

            ) {

                throw new \Exception(

                    'Company mobile missing'

                );

            }

            // =============================================

            // DESTINATION

            // =============================================

            $destination = $this->formatMobile(

                $company->contact_phone

            );

            // =============================================

            // TEMPLATE PARAMS

            // =============================================

            $templateParams = [

                // 1. USER NAME

                trim(

                    (

                        ($company->business_name ?? '') .

                        ' ' .

                        ($company->company_code ?? '')

                    )

                ) ?: 'User',

                // 2. ARP ID

                $arpId ?? 'ARP001',

                // 3. PAYMENT RECEIPT ID

                $receiptId ?? 'RCPT001',

                // 4. AMOUNT

                number_format(

                    (float)($amount ?? 0),

                    2,

                    '.',

                    ''

                ),

                // 5. DATE

                $date ??

                now()->format('d-m-Y'),

                // 6. CLOSING BALANCE

                number_format(

                    (float)($closingBalance ?? 0),

                    2,

                    '.',

                    ''

                )

            ];

            // =============================================

            // TEMPLATE JSON

            // =============================================

            $template = json_encode([

                'id' =>

                    'af4a7194-e028-4cfc-83fa-e0a0e7a53004',

                'params' =>

                    $templateParams

            ], JSON_UNESCAPED_SLASHES);

            // =============================================

            // SEND REQUEST

            // =============================================

            return $this->sendRequest([

                'channel' =>

                    'whatsapp',

                'source' =>

                    $this->source,

                'destination' =>

                    $destination,

                'src.name' =>

                    $this->appName,

                'template' =>

                    $template

            ]);

        } catch (\Throwable $e) {

            Log::error(

                'PAYMENT RECEIPT WHATSAPP FAILED',

                [

                    'company_id' =>

                        $company->id ?? null,

                    'message' =>

                        $e->getMessage(),

                    'line' =>

                        $e->getLine(),

                    'file' =>

                        $e->getFile()

                ]

            );

            return [

                'success' => false,

                'message' =>

                    $e->getMessage()

            ];

        }

    }
    
    //
    
 public function sendSubscriptionActivatedWhatsapp(
    $company,
        $subscription
    ) {

    try {

        // =====================================================
        // VALIDATION
        // =====================================================

        if (!$company) {

            throw new \Exception(
                'Retailer company not found'
            );
        }

        if (empty($company->contact_phone)) {

            throw new \Exception(
                'Retailer mobile missing'
            );
        }

        // =====================================================
        // DESTINATION
        // =====================================================

        $destination = $this->formatMobile(
            $company->contact_phone
        );

        // =====================================================
        // TEMPLATE PARAMS
        // =====================================================

        $params = [

            // 1. User Name
            trim(
                $company->business_name
                    ?? 'Retailer'
            ),

            // 2. Subscription ID
            trim(
                $subscription->subscription_code
                    ?? 'SUB001'
            ),

            // 3. Retailer Code
            trim(
                $company->company_code
                    ?? 'ARP001'
            ),

            // 4. Package Name
            trim(
                $subscription->package_name
                    ?? 'Warranty Package'
            ),

            // 5. Start Date
            !empty($subscription->start_date)
                ? Carbon::parse(
                    $subscription->start_date
                )->format('d-m-Y')
                : now()->format('d-m-Y'),

            // 6. Validity Days
            (string) (
                $subscription->validity_days
                    ?? 0
            ),

            // 7. Expiry Date
            !empty($subscription->end_date)
                ? Carbon::parse(
                    $subscription->end_date
                )->format('d-m-Y')
                : now()->format('d-m-Y'),

            // 8. Remaining Balance
            (string) (
                $subscription->balance
                    ?? 0
            ),

            // 9. Link
            config('app.url')
        ];

        // =====================================================
        // TEMPLATE
        // =====================================================

        $template = json_encode([

            'id' =>
                '65e5ed51-b569-4967-8b94-da4c435cd6bc',

            'params' => $params

        ], JSON_UNESCAPED_SLASHES);

        // =====================================================
        // SEND REQUEST
        // =====================================================

        $result = $this->sendRequest([

            'channel' =>
                'whatsapp',

            'source' =>
                $this->source,

            'destination' =>
                $destination,

            'src.name' =>
                $this->appName,

            'template' =>
                $template
        ]);

        // =====================================================
        // LOG
        // =====================================================

        Log::info(
            'SUBSCRIPTION ACTIVATED WHATSAPP SENT',
            [

                'retailer_id' =>
                    $company->id,

                'subscription_id' =>
                    $subscription->id,

                'destination' =>
                    $destination,

                'params' =>
                    $params,

                'response' =>
                    $result
            ]
        );

        return $result;

    } catch (\Throwable $e) {

        Log::error(
            'SUBSCRIPTION ACTIVATED WHATSAPP FAILED',
            [

                'retailer_id' =>
                    $company->id ?? null,

                'subscription_id' =>
                    $subscription->id ?? null,

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return [

            'success' => false,

            'message' => $e->getMessage()
        ];
    }
}

public function sendWalletDeductedWhatsapp(
    $company,
    $transactionId,
    $amount,
    $warrantyId,
    $date,
    $closingBalance
) {

    try {

        if (!$company) {

            throw new \Exception(
                'Retailer company not found'
            );
        }

        if (empty($company->contact_phone)) {

            throw new \Exception(
                'Retailer mobile missing'
            );
        }

        // =====================================================
        // DESTINATION
        // =====================================================

        $destination = $this->formatMobile(
            $company->contact_phone
        );

        // =====================================================
        // TEMPLATE PARAMS
        // =====================================================

        $params = [

            // 1. Retailer Name
            trim(
                $company->business_name
                    ?? 'Retailer'
            ),

            // 2. Retailer Code
            trim(
                $company->company_code
                    ?? 'ARP001'
            ),

            // 3. Transaction ID
            $transactionId,

            // 4. Amount
            number_format(
                (float) $amount,
                2,
                '.',
                ''
            ),

            // 5. Warranty ID
            $warrantyId,

            // 6. Date
            $date,

            // 7. Closing Balance
            number_format(
                (float) $closingBalance,
                2,
                '.',
                ''
            )
        ];

        // =====================================================
        // TEMPLATE
        // =====================================================

        $template = json_encode([

            'id' =>
                '2df785ba-df91-44f7-8517-70d295dbe985',

            'params' => $params

        ], JSON_UNESCAPED_SLASHES);

        // =====================================================
        // SEND
        // =====================================================

        $result = $this->sendRequest([

            'channel' =>
                'whatsapp',

            'source' =>
                $this->source,

            'destination' =>
                $destination,

            'src.name' =>
                $this->appName,

            'template' =>
                $template
        ]);

        Log::info(
            'WALLET DEDUCTED WHATSAPP SENT',
            [

                'company_id' =>
                    $company->id,

                'destination' =>
                    $destination,

                'params' =>
                    $params,

                'response' =>
                    $result
            ]
        );

        return $result;

    } catch (\Throwable $e) {

        Log::error(
            'WALLET DEDUCTED WHATSAPP FAILED',
            [

                'company_id' =>
                    $company->id ?? null,

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return [

            'success' => false,

            'message' => $e->getMessage()
        ];
    }
}

/**
 * =====================================================
 * ONBOARDED SUCCESSFULLY WHATSAPP
 * =====================================================
 */

public function onboardedSuccessfullyWhatsapp($company)
{
    try {

        // =============================================
        // VALIDATION
        // =============================================

        if (!$company) {

            throw new \Exception(
                'Company not found'
            );
        }

        if (empty($company->contact_phone)) {

            throw new \Exception(
                'Company mobile missing'
            );
        }

        // =============================================
        // DESTINATION
        // =============================================

        $destination = $this->formatMobile(
            $company->contact_phone
        );

        // =============================================
        // LOGIN URL
        // =============================================

        $loginUrl = rtrim(
            config('app.retailer_panel_url'),
            '/'
        ) . '/signin';

        // =============================================
        // ROLE LABEL
        // =============================================

        $roleLabel = match ((int) $company->role) {

            2 => 'MCP',

            4 => 'CP',

            5 => 'ARP',

            6 => 'PRO',

            default => 'Retailer'
        };

        // =============================================
        // TEMPLATE
        // =============================================

        $template = json_encode([

            'id' =>
                '9c5e81a2-e7d8-47bc-a4cf-15b90a5cacdf',

            'params' => [

                trim(
                    (
                        ($company->business_name ?? '') .
                        ' ' .
                        ($company->company_code ?? '')
                    )
                ) ?: 'Org Name',

                $roleLabel,

                $company->contact_email ?? 'User',

                $loginUrl
            ]

        ], JSON_UNESCAPED_SLASHES);

        // =============================================
        // SEND REQUEST
        // =============================================

        return $this->sendRequest([

            'channel' =>
                'whatsapp',

            'source' =>
                $this->source,

            'destination' =>
                $destination,

            'src.name' =>
                $this->appName,

            'template' =>
                $template
        ]);

    } catch (\Throwable $e) {

        Log::error(
            'ONBOARD SUCCESS WHATSAPP FAILED',
            [

                'company_id' =>
                    $company->id ?? null,

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return [

            'success' => false,

            'message' =>
                $e->getMessage()
        ];
    }
}

 public function sendOtpTemplate(

        $mobile,

        $otp,

        $referenceCode = null

    ) {

        try {

            /*

            |--------------------------------------------------------------------------

            | CLEAN MOBILE

            |--------------------------------------------------------------------------

            */

            $mobile = preg_replace(

                '/[^0-9]/',

                '',

                $mobile

            );

            /*

            |--------------------------------------------------------------------------

            | ADD COUNTRY CODE

            |--------------------------------------------------------------------------

            */

            if (strlen($mobile) == 10) {

                $mobile = '91' . $mobile;

            }

            /*

            |--------------------------------------------------------------------------

            | DEFAULT REFERENCE

            |--------------------------------------------------------------------------

            */

            $referenceCode =

                $referenceCode ?: $otp;

            /*

            |--------------------------------------------------------------------------

            | TEMPLATE PAYLOAD

            |--------------------------------------------------------------------------

            */

            $template = [

                'id' =>

                    '8d3e0965-dd63-4fce-a0aa-5e94aac810bc',

                'params' => [

                    (string) $otp,

                    (string) $referenceCode

                ]

            ];

            /*

            |--------------------------------------------------------------------------

            | API REQUEST

            |--------------------------------------------------------------------------

            */

            $response = Http::asForm()

                ->timeout(60)

                ->withHeaders([

                    'apikey' =>

                        env('GUPSHUP_API_KEY'),

                    'Cache-Control' =>

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

                            $mobile,

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

            | RESPONSE

            |--------------------------------------------------------------------------

            */

            $responseBody =

                $response->json();

            Log::info(

                'OTP WHATSAPP SENT',

                [

                    'mobile' =>

                        $mobile,

                    'otp' =>

                        $otp,

                    'response' =>

                        $responseBody

                ]

            );

            return [

                'status' => true,

                'response' =>

                    $responseBody

            ];

        } catch (\Throwable $e) {

            Log::error(

                'OTP WHATSAPP FAILED',

                [

                    'mobile' =>

                        $mobile ?? null,

                    'otp' =>

                        $otp ?? null,

                    'message' =>

                        $e->getMessage(),

                    'line' =>

                        $e->getLine(),

                    'file' =>

                        $e->getFile()

                ]

            );

            return [

                'status' => false,

                'message' =>

                    $e->getMessage()

            ];

        }

    }
}