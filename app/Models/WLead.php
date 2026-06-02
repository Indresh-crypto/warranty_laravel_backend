<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WLead extends Model  
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
        'address1',
        'address2',
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
        'form_ref',
        'agent_id',
        'pay_now',
        'pay_later',
        'products',
        'owner_first_name',
        'owner_middle_name',
        'owner_last_name',
        'followup_date',
        'follow_up_by'
        
    ];
    
    public function remarks()
    {
    
        return $this->hasMany(WLeadRemark::class, 'lead_id')
    
            ->latest();
    }
    

    public function followupUser()
    {
    
        return $this->belongsTo(CompanyEmployee::class, 'follow_up_by');
    
    }


}