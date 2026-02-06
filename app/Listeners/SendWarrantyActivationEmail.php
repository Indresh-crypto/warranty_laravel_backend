<?php

namespace App\Listeners;

use App\Events\WarrantyRegistered;
use App\Mail\WarrantyActivationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendWarrantyActivationEmail
{
    public function handle(WarrantyRegistered $event): void
    {
        Log::info('SendWarrantyActivationEmail listener HIT', [
            'device_id' => $event->device->id
        ]);

        $device = $event->device->loadMissing([
            'customer',
            'product.coverages'
        ]);

        if (!$device->customer || !$device->product) {
            Log::error('Warranty mail skipped (listener)', [
                'device_id' => $device->id
            ]);
            return;
        }

        Mail::to($device->customer->email)
            ->queue(new WarrantyActivationMail($device));
    }
}