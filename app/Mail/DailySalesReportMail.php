<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

class DailySalesReportMail extends Mailable
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
        return $this->subject('Daily Sales Report - ' . $this->date)
            ->view('emails.daily_sales_report');
    }
}