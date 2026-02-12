<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarrantyInvoice extends Model
{
    protected $table = 'warranty_accounting';

    protected $fillable = [
        'invoice_id',
        'customer_id',
        'product_id',
        'retailer_id',
        'status',
        'inv_amount',
        'bal_amount',
        'company_id',
        'promoter_id'
    ];

    public function customer()
    {
        return $this->belongsTo(WCustomer::class, 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(WarrantyProduct::class, 'product_id');
    }

    public function retailer()
    {
        return $this->belongsTo(OrgUser::class, 'retailer_id')->where('role', 7);
    }

    public function company()
    {
        return $this->belongsTo(OrgUser::class, 'company_id')->where('role', 2);
    }
}