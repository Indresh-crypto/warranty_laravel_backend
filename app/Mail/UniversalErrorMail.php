<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class UniversalErrorMail extends Mailable
{
    public $exception;
    public $requestData;

    public function __construct($exception, $requestData = [])
    {
        $this->exception = $exception;
        $this->requestData = $requestData;
    }

    public function build()
    {
        return $this->subject('🚨 Laravel Universal Error Alert')
            ->view('emails.universal_error');
    }
}