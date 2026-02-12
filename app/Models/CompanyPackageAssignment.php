<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPackageAssignment extends Model
{
  
      protected $fillable = [
        'company_id',
        'company_name',
        'company_email',
        'company_phone',
        'package_id',
        'badge_id',
        'start_date',
        'expiry_date',
        'amount',
        'benefits',
        'eligibility',
        'is_default'
    ];

    public function package()
    {
        return $this->belongsTo(OnboardingPackage::class, 'package_id');
    }

    public function badge()
    {
        return $this->belongsTo(WBadge::class, 'badge_id');
    }
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}