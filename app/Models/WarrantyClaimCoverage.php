<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyClaimCoverage extends Model
{
    protected $table = 'warranty_claim_coverages';

    protected $fillable = [
        'warranty_claim_id',
        'coverage_id',
        'coverage_title'
    ];
}