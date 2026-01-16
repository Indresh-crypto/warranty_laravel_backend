<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoInvoiceLog extends Model
{
    protected $table = 'zoho_invoice_logs';

    protected $fillable = [
        'company_id',
        'invoice_id_parent',
        'invoice_number',
        'zoho_invoice_id',
        'zoho_status',
        'is_sent',
        'sent_at',
        'request_payload',
        'response_payload',
    ];
}