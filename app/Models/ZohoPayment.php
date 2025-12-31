<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZohoPayment extends Model
{

    protected $fillable = [
       'z_json',   
       'contact_id',
       'payment_id',
       'contact_id',
       'org_id',
        'company_id',
        'user_id',
        'role',
        'amount',
        'date',
        'created_by',
        'customer_name',
        'description',
        'payment_mode',
        'reference_number',
        'payment_number',
        'org_code',
        'org_name'

    ];
}