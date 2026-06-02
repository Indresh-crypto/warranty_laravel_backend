<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribedPackage extends Model
{
    protected $fillable = [
        'retailer_id',
        'package_id',
        'company_id',
        'subscription_code',
        'agent_payout',
        'company_payout',
        'retailer_out',
        'emp_payout',
        'start_date',
        'end_date',
        'balance',
        'enroll_max',
        'status',
        'last_used_date',
        'payment_id',
        'zoho_payment_id',
        'zoho_invoice_id',
        'payment_mode',
        'company_package_id',
        'amount',
        'invoice_json',
        'payment_json',
        'validity_days',
        'invoice_created_date',
        'invoice_status'
    ];

    // =====================================================
    // RETAILER (COMPANY TABLE)
    // =====================================================

    public function retailer()
    {
        return $this->belongsTo(Company::class, 'retailer_id', 'id');
    }

    // =====================================================
    // PACKAGE
    // =====================================================

    public function package()
    {
        return $this->belongsTo(WarrantyProduct::class, 'package_id');
    }

    // =====================================================
    // COMPANY
    // =====================================================

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}