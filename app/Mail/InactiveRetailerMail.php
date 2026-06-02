<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

class InactiveRetailerMail extends Mailable
{
    use Queueable, SerializesModels;

    public $retailers;
    public $days;

    public function __construct($retailers, $days = 2)
    {
        $this->retailers = $retailers;
        $this->days = $days;
    }

    public function build()
    {
        return $this->subject('Inactive Retailers Alert - No Activity Since ' . $this->days . ' Days')
            ->view('emails.inactive_retailers');
    }
}