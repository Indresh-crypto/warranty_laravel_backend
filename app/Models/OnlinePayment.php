<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnlinePayment extends Model
{

    protected $fillable = [
        'org_id',
        'company_id',
        'user_id',
        'payment_id',
        'amount',
        'status',
        'payment_date',
        'invoice_id',
        'invoice_number',
        'customer_id',
        'payment_from',
        'zoho_response',
        'zoho_status',
        'is_captured',
        'capture_response'

    ];
}