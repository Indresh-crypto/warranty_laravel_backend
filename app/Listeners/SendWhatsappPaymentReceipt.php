<?php

namespace App\Listeners;

use App\Events\PaymentSuccessful;
use GuzzleHttp\Client;
use App\Models\Company;
use App\Models\WDevice;
use Illuminate\Support\Facades\Log;

class SendWhatsappPaymentReceipt
{
   public function handle(PaymentSuccessful $event){}
}