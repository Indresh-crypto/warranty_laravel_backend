<?php

namespace App\Listeners;

use App\Events\WarrantyRegisterWhatsapp;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

// ✅ QUEUE IMPORTS
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use App\Models\Company;
use App\Models\WDevice;
class SendWarrantyRegisterWhatsapp implements ShouldQueue
{
    use InteractsWithQueue;


  public function handle(WarrantyRegisterWhatsapp $event): void
{
    // 🔥 Reload device with relations (queue-safe)
    $device = WDevice::with('customer')->find($event->device->id);

    Log::warning('Warranty IMM debug', [
        'event_device_id' => $event->device->id ?? null,
        'db_device_id' => $device->id ?? null,
        'w_customer_id' => $device->w_customer_id ?? null,
        'customer_loaded' => isset($device->customer),
        'customer_mobile' => $device->customer->mobile ?? null,
    ]);

    // ✅ Proper phone check
    if (!$device || !$device->customer || empty($device->customer->mobile)) {
        Log::warning('Warranty IMM skipped: phone missing', [
            'device_id' => $event->device->id
        ]);
        return;
    }

    $customer = $device->customer;

    // ✅ Certificate check (still required)
    if (empty($device->certificate_link)) {
        Log::warning('Warranty IMM skipped: certificate link missing', [
            'device_id' => $device->id
        ]);
        return;
    }

    $destination = '91' . ltrim($customer->mobile, '0');

    $companyDetails = Company::find($device->company_id);
    $companyName = $companyDetails->business_name ?? 'Goelectronix';

    try {
        $client = new Client();

        $client->post(
            'https://api.gupshup.io/wa/api/v1/template/msg',
            [
                'headers' => [
                    // ✅ FIXED KEY
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

        Log::info('Warranty WhatsApp sent successfully', [
            'device_id' => $device->id
        ]);

    } catch (\Exception $e) {
        Log::error('Warranty WhatsApp failed', [
            'device_id' => $device->id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
}