<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DailyNoSalesReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $company;
    public $loginUrl;

    public function __construct(Company $company, $loginUrl)
    {
        $this->company  = $company;
        $this->loginUrl = $loginUrl;
    }

    public function build()
    {
        return $this->subject('Daily Sales Reminder - No Sales Recorded Today')
            ->markdown('emails.daily_no_sales')
            ->with([
                'company'  => $this->company,
                'loginUrl' => $this->loginUrl,
            ]);
    }
}