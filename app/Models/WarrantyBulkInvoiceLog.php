<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyBulkInvoiceLog extends Model
{
    protected $fillable = [
        'company_id',
        'retailer_id',
        'invoice_id',
        'status',
        'device_count',
        'total_amount',
        'response_json',
        'error_message'
    ];
}