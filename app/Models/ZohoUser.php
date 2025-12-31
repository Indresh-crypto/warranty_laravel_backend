<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Branch;

use Spatie\Permission\Models\Role;

class ZohoUser extends Authenticatable
{
    use Notifiable;
    use HasRoles;
    protected $fillable = [
        'business_name',
        'mobile',
        'state',
        'district',
        'taluka',
        'pincode',
        'address',
        'gst',
        'pan',
        'document',
        'role',
        'logo',
        'owner_name',
        'company_id',
        'company_name',
        'password',
        'email',
        'is_active',
        'is_deleted',
        'user_id',
        'org_code',
        'zoho_refresh_token',
        'zoho_client_id', 
        'zoho_client_secret', 
        'zoho_redirect_uri',
        'zoho_access_token',
        'zoho_org_id',
        'zoho_contact_id',
        'zoho_org_id_parent',
        'client_id',
        'client_secret',
        'senior_id',
        'created_by',
        'z_billing_id',
        'z_shipping_id'
    ];
}