<?php

namespace App\Services;

use App\Models\WDevice;
use App\Models\Company;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WhatsappService
{
    public function sendWarranty(WDevice $device): void
    {
        $device->load('customer');

        if (!$device->customer || empty($device->customer->mobile)) {
            throw new \Exception('Customer mobile missing');
        }

        if (empty($device->certificate_link)) {
            throw new \Exception('Certificate link missing');
        }

        $destination = '91' . ltrim($device->customer->mobile, '0');

        $companyDetails = Company::find($device->company_id);
        $companyName = $companyDetails->business_name ?? 'Goelectronix';

        $client = new Client();

        $client->post(
            'https://api.gupshup.io/wa/api/v1/template/msg',
            [
                'headers' => [
                    'apikey' => config('services.gupshup.key'),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'channel' => 'whatsapp',
                    'source' => '15557661628',
                    'destination' => $destination,
                    'src.name' => 'GoelectronixWarranty',
                    'template' => json_encode([
                        'id' => '7daef5bb-b87c-41e8-a646-b179277da272',
                        'params' => [
                            $device->customer->name,
                            $device->brand_name,
                            $device->model,
                            $device->imei1 ?? $device->serial,
                            $device->category_name,
                            \Carbon\Carbon::parse($device->expiry_date)->format('d-m-Y'),
                            $device->product_name,
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

        Log::info('Warranty WhatsApp sent', ['device_id' => $device->id]);
    }
    
    public function sendWarrantyProvision(WDevice $device): void
    {
    $device->load('customer');

    if (!$device->customer || empty($device->customer->mobile)) {
        throw new \Exception('Customer mobile missing');
    }

    $destination = '91' . ltrim($device->customer->mobile, '0');

    $companyDetails = Company::find($device->company_id);
    $companyName = $companyDetails->business_name ?? 'Goelectronix';

    $client = new Client();

    $client->post(
        'https://api.gupshup.io/wa/api/v1/template/msg',
        [
            'headers' => [
                'apikey' => config('services.gupshup.key'),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'channel' => 'whatsapp',
                'source' => '15557661628',
                'destination' => $destination,
                'src.name' => 'GoelectronixWarranty',
                'template' => json_encode([
                    'id' => 'b7784344-7471-4e7b-aa14-a14b74a0ad71',
                    'params' => [
                        $device->customer->name,                              // Customer name
                        $device->brand_name,                                  // Brand
                        $device->model,                                       // Model
                        $device->imei1 ?? $device->serial,                    // Serial / IMEI
                        $device->category_name,                               // Category
                        \Carbon\Carbon::parse($device->expiry_date)->format('d-m-Y'), // Validity
                        $device->product_name,                                // Plan name
                        "+919372011028",                                       // Support No
                        "hello@goelectronix.com",                              // Email
                        $companyName,                                          // Company Name

                        // Extra template static placeholders
                        $device->customer->name,
                        "Device:",
                        $device->model,
                        "SR",
                        "📄",
                        "Provisional",
                        \Carbon\Carbon::parse($device->expiry_date)->format('d-m-Y'),
                        "contact",
                        "anytime:",
                        "Email"
                    ],
                ]),
            ],
        ]
    );

    Log::info('Provisional Warranty WhatsApp sent', [
        'device_id' => $device->id
    ]);
}
}