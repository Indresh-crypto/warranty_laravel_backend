<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WLead extends Model   // <-- FIXED casing
{
    protected $table = 'w_leads';

    protected $fillable = [
        'name',
        'owner_name',
        'phone',
        'state',
        'district',
        'pincode',
        'email',
        'address_full',
        'password',
        'status',
        'lead_amount',
        'created_by_id',
        'created_by_name',
        'remark',
        'updated_by_id',
        'updated_by_name',
        'lead_type',
        'package_id',
        'package_name',
        'badge_name',
        'badge_id',
        'benefits',
        'eligibility',
        'company_id',
        'state_in',
        'district_in',
        'lead_code',
        'manager_id',
        'formdata',
        'form_ref'
    ];
}