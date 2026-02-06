<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetailerConnection extends Model
{
    protected $fillable = [
        'retailer_id',
        'created_by_id',
        'created_by_name',
        'note'
    ];

    // created_by_id -> company_employee.id
    public function employee()
    {
        return $this->belongsTo(CompanyEmployee::class, 'created_by_id');
    }

    // retailer_id -> companies.id
    public function company()
    {
        return $this->belongsTo(Company::class, 'retailer_id');
    }
}