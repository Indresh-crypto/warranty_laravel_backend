<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoContactData extends Model
{
    protected $table = 'zoho_contact_data';

    protected $fillable = [
        'contact_id',
        'contact_name',
        'company_name',
        'contact_type',
        'status',
        'payment_terms',
        'payment_terms_label',
        'currency_id',
        'currency_code',
        'outstanding_receivable_amount',
        'unused_credits_receivable_amount',
        'first_name',
        'last_name',
        'email',
        'phone',
        'mobile',
        'created_time',
        'last_modified_time',
        'z_json',
        'zoho_org_id',
        'user_id'
    ];

    protected $casts = [
        'z_json' => 'array',
        'created_time' => 'datetime',
        'last_modified_time' => 'datetime',
    ];
}