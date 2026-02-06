<?php

namespace App\Listeners;

use App\Events\ClaimStatusUpdated;
use App\Mail\ClaimStatusMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendClaimStatusEmail
{
    public function handle(ClaimStatusUpdated $event): void
    {
        $claim = $event->claim->loadMissing([
            'customer',
            'device',
            'assignment'
        ]);

        if (!$claim->customer || !$claim->device) {
            Log::error('Claim status mail skipped', [
                'claim_id' => $claim->id,
                'status' => $event->status
            ]);
            return;
        }

        // 📧 Customer
        if ($claim->customer->email) {
            Mail::to($claim->customer->email)
                ->queue(new ClaimStatusMail($claim, $event->status));
        }

        // 📧 Company
        Mail::to('hello@goelectronix.com')
            ->queue(new ClaimStatusMail($claim, $event->status, true));
    }
}