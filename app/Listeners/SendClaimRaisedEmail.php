<?php

namespace App\Listeners;

use App\Events\ClaimRaised;
use App\Mail\ClaimRaisedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendClaimRaisedEmail
{
    public function handle(ClaimRaised $event): void
    {
        $claim = $event->claim->loadMissing([
            'customer',
            'device',
            'photos'
        ]);

        if (!$claim->customer || !$claim->device) {
            Log::error('Claim mail skipped due to missing relation', [
                'claim_id' => $claim->id
            ]);
            return;
        }

        // 📧 Customer email
        if ($claim->customer->email) {
            Mail::to($claim->customer->email)
                ->queue(new ClaimRaisedMail($claim));
        }

        // 📧 Company email
        Mail::to('hello@goelectronix.com')
            ->queue(new ClaimRaisedMail($claim, true));
    }
}