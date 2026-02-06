<?php

namespace App\Listeners;

use App\Events\WarrantyRegisteredProvision;
use App\Mail\WarrantyProvisionMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendWarrantyProvisionEmail
{
    public function handle(WarrantyRegisteredProvision $event): void
    {
        Log::info('SendWarrantyProvisionEmail listener HIT', [
            'device_id' => $event->device->id
        ]);

        $device = $event->device->loadMissing([
            'customer',
            'product.coverages'
        ]);

        if (!$device->customer || !$device->product) {
            Log::error('Warranty provision mail skipped', [
                'device_id' => $device->id
            ]);
            return;
        }

        Mail::to($device->customer->email)
            ->queue(new WarrantyProvisionMail($device));
    }
}