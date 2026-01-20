<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyFlowLog extends Model
{
    protected $table = 'warranty_flow_logs';

    protected $fillable = [
        'payment_id',
        'device_id',
        'invoice_id',
        'zoho_payment_id',
        'step',
        'status',
        'request_data',
        'response_data',
        'error_message'
    ];
}