<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Auth\Passwords\CanResetPassword as ResetPasswordTrait;
use Illuminate\Auth\Notifications\ResetPassword;
use Laravel\Sanctum\HasApiTokens;

class Company extends Model implements CanResetPassword
{

 use Notifiable, ResetPasswordTrait, HasApiTokens;
 
   protected $hidden = [
        'password',
    
        // Zoho sensitive data
      //  'zoho_access_token',
      //  'zoho_client_id',
//'zoho_client_secret',
     //   'zoho_redirect_uri',
     //   'zoho_refresh_token'
    ];


    protected $table = 'companies';

    protected $fillable = [
        'business_name',
        'contact_person',
        'contact_phone',
        'contact_email',
        'password',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'district',
        'pincode',
        'status',
        'pan',
        'gst',
        'business_type',
        'is_verified',
        'is_payment_success',
        'trade_name',
        'account_no',
        'ifsc_code',
        'bank_name',
        'branch_name',
        'role',
        'esign_verified',
        'company_id',
        'account_type',
        'pan_verified',
        'pan_json',
        'document_id',
        'esign_json',
        'company_code',
        'otp',
        'is_mail_verified',
        'is_wa_verified',
        'zoho_access_token',
        'zoho_org_id',
        'zoho_client_id',
        'zoho_client_secret',
        'zoho_redirect_uri',
        'zoho_refresh_token',
        'state_as',
        'district_as',
        'pincode_as',
        'user_type',
        'senior_id',
        'zoho_id',
        'owner_first_name',
        'owner_middle_name',
        'owner_last_name',
        'owner_email',
        'owner_contact',
        'wa_response',
        'gst_json',
        'bank_json',
        'bank_verified',
        'gst_verified',
        'agent_code',
        'payment_type',
        'is_logout',
        'agent_id',
        'pay_now',
        'pay_later',
        'admin_paynow_status',
        'admin_paylater_status',
        'admin_status_change_date',
        'auto_status_change_date',
        'logo',
        'domain',
        'color',
        'favicon',
        'title',
        'is_password_changed',
        'created_by_id',
        'created_by_name',
        'otp_expires_at',
        'm_pin',
        'is_mpin_active',
        'wallet_balance',
        'last_update_balance_at',
        'zoho_receivable_balance',
        'zoho_unused_credit_balance',
        'zoho_last_sync_at',
        'allow_wallet',
        'is_subscribed',
        'catalog',
        'last_sold_at',
        'flag'
    ];

    protected $casts = [
        'pan_json' => 'array',
        'gst_json' => 'array',
        'bank_json' => 'array',
    ];


    public function leads()
    {
        return $this->hasMany(WLead::class, 'email', 'contact_email');
    }
    
      public function getEmailForPasswordReset()
    {
        return $this->contact_email;
    }


    public function routeNotificationForMail()
    {
        return $this->contact_email;
    }

    // Explicitly send reset notification
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPassword($token));
    }
    
    
    public function retailerConnections()
    {
        return $this->hasMany(RetailerConnection::class, 'retailer_id');
    }
    public function devices()
    {
        return $this->hasMany(WDevice::class, 'company_id');
    }
    
    public function updateVerificationData($type, $pan, $response)
    {
        switch ($type) {
    
            case 'pan':
                $this->pan_verified = 1;
                $this->pan_json = $response;
                $this->pan = $pan;
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
    
    public function parent()
    {
        return $this->belongsTo(Company::class, 'created_by_id');
    }
    public function wDevices()
    {
        return $this->hasMany(WDevice::class, 'company_id');
    }
}