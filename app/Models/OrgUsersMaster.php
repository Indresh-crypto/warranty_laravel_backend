<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgUsersMaster extends Model
{
    protected $table = 'org_users_master';

    protected $fillable = [
        'business_name',
        'org_code',
        'phone',
        'email',
        'state',
        'city',
        'pincode',
        'company_id',
        'products',
        'role',
        'zoho_refresh_token',
        'zoho_access_token',
        'zoho_contact_id',
        'zoho_org_id',
        'zoho_contact_json',
        'zoho_client_id',
        'zoho_client_secret',
        'is_wa_verified',
        'is_mail_verified',
        'pan_verified',
        'esign_verified',
        'bank_verified',
        'gst_verified',
        'pan_json',
        'gst_json',
        'bank_json',
        'catalog',
        'address',
        'pan',
        'owner_name'
        
    ];

      
      protected $casts = [
            'products' => 'array',
            'zoho_contact_json' => 'array',
            'pan_json' => 'array',
            'gst_json' => 'array',
            'bank_json' => 'array',
        ];


        
    public function updateVerificationData($type, $response)
    {
    switch ($type) {
        case 'pan':
            $this->pan_verified = 1;
            $this->pan_json = $response;
            break;

        case 'gst':
            $this->gst_verified = 1;
            $this->gst_json = $response;
            break;

        case 'bank':
            $this->bank_verified = 1;
            $this->bank_json = $response;
            break;
    }

    $this->save();
}

}