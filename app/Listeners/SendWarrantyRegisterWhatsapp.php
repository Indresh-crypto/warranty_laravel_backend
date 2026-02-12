<?php

namespace App\Listeners;

use App\Events\WarrantyRegisterWhatsapp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

use App\Models\WDevice;
use App\Services\WarrantyCertificateService;
use App\Services\WhatsappService;

class SendWarrantyRegisterWhatsapp implements ShouldQueue
{
    use InteractsWithQueue;

    protected WarrantyCertificateService $certificateService;
    protected WhatsappService $whatsappService;

    public function __construct(
        WarrantyCertificateService $certificateService,
        WhatsappService $whatsappService
    ) {
        $this->certificateService = $certificateService;
        $this->whatsappService = $whatsappService;
    }

    public function handle(WarrantyRegisterWhatsapp $event): void
    {
        try {

            $device = WDevice::find($event->device->id);

            if (!$device) {
                Log::error('Device not found in listener', [
                    'device_id' => $event->device->id
                ]);
                return;
            }

            // 1️⃣ Generate certificate
            $certificate = $this->certificateService->generate($device);

            // 2️⃣ Send WhatsApp
            $this->whatsappService->sendWarranty($device);

            Log::info('Certificate + WhatsApp completed', [
                'device_id' => $device->id
            ]);

        } catch (\Throwable $e) {

            Log::error('Listener failed', [
                'device_id' => $event->device->id ?? null,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            throw $e; // allow retry
        }
    }
}