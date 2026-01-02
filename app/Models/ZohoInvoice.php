<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZohoInvoice extends Model
{
    protected $fillable = [
      	'zoho_json',	
        'invoice_id',	
        'contact_id',
        'org_id', 
        'user_id',
        'role', 
        'company_id',      	
        'created_by_id',
        'created_by_name',
        'invoice_status',
        'due_date',
        'payment_date',
        'invoice_amount',
        'balance_amount',
        'product_type',
        'state',
        'district',
        'taluka',
        'pincode',
        'invoice_number',
        'customer_name',
        'due_days',
        'invoice_url',
        'salesperson_name',
        'email',
        'write_off_amount',
        'recovery_agent_id',
        'org_code',
        'org_name',
        'invoice_date',
        'org_mobile'
    ];
}