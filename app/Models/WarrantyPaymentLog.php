<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class WarrantyPaymentLog extends Model
{
    protected $fillable = [
        'payment_id',
        'order_id',
        'device_id',
        'invoice_id',
        'zoho_payment_id',
        'step',
        'status',
        'request_payload',
        'response_payload',
        'error_message'
    ];
}