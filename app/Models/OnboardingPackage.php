<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingPackage extends Model
{
    protected $fillable = [
        'badge_id',
        'package_name',
        'validity_days',
        'amount',
    ];

    public function badge()
    {
        return $this->belongsTo(WBadge::class, 'badge_id');
    }
}