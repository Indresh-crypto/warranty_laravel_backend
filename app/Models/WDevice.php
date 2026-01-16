<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WDevice extends Model
{
    use HasFactory;

    protected $table = 'w_devices';
    protected $fillable = [  
        'id',
        'name',
        'imei1',
        'imei2',
        'serial',
        'brand_id',
        'category_id',
        'product_id',
        'product_name',
        'brand_name',
        'category_name',
        'available_claim',
        'expiry_date',
        'invoice_id',
        'w_customer_id',
        'document_url',
        'retailer_id',
        'is_approved',
        'created_at',
        'updated_at',
        'model',
        'device_price',
        'promoter_id',
        'retailer_payout',
        'employee_payout',
        'other_payout',
        'company_payout',
        'certificate_link',
        'template_id',
        'status_remark',
        'link1',
        'link2',
        'product_price',
        'agent_id',
        'invoice_created_date',
        'reject_remark',
        'credit_note',
        'cd_issued_date',
        'note',
        'company_id',
        'created_by',
        'w_code',
        'whatsapp_sent',
        'whatsapp_sent_at',
        'product_mrp',
        'invoice_created_date_parent',
        'invoice_id_parent',
        'invoice_status_parent',
        'invoice_status',
        'invoice_json_parent',
        'invoice_json'
        
    ];

    public function customer()
    {
        return $this->belongsTo(WCustomer::class, 'w_customer_id');
    }
    public function product()
    {
        return $this->belongsTo(WarrantyProduct::class, 'product_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function promoter()
    {
        return $this->belongsTo(Company::class, 'promoter_id');
    }
}
