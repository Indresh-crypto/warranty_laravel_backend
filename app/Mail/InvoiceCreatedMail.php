<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $invoice,
        public string $invoiceUrl
    ) {}

    public function build()
    {
        return $this->subject('🧾 Invoice Generated – Action Required')
            ->markdown('emails.invoice_created', [
                'invoice' => $this->invoice,
                'invoiceUrl' => $this->invoiceUrl
            ]);
    }
}