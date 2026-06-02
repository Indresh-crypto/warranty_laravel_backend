<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
class RetailerPaymentDoneMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $company;
    public $paymentId;
    public $amount;

    public function __construct($company, $paymentId, $amount)
    {
        $this->company   = $company;
        $this->paymentId = $paymentId;
        $this->amount    = $amount;
    }

    public function build()
    {
        
         Log::error('INSIDER', [
                'payload' => $this->company
            ]);
            
        return $this->subject('Payment Successful')
            ->markdown('emails.retailer_payment_completed')
            ->with([
                'company'   => $this->company,
                'paymentId' => $this->paymentId,
                'amount'    => $this->amount
            ]);
    }
}