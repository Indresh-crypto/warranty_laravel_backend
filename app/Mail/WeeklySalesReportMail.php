<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

class WeeklySalesReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public $reportData;
    public $totalQty;
    public $totalAmount;
    public $date;

    public function __construct($reportData, $totalQty, $totalAmount, $date)
    {
        $this->reportData  = $reportData;
        $this->totalQty    = $totalQty;
        $this->totalAmount = $totalAmount;
        $this->date        = $date;
    }

    public function build()
    {
        return $this->subject('Weekly Sales Report - ' . $this->date)
            ->view('emails.weekly_sales_report');
    }
}