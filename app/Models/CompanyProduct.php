<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyProduct extends Model
{
    use HasFactory;

    protected $table = 'w_company_product';

    protected $fillable = [
    'product_id',
    'company_id',
    'margin',
    'p_status',
    'zoho_item_id',
    'zoho_json'
];

    public function product()
    {
        return $this->belongsTo(WarrantyProduct::class, 'product_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }
}