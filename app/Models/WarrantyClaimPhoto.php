<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyClaimPhoto extends Model
{
    protected $table = 'warranty_claim_photos';

    protected $fillable = [
        'warranty_claim_id',
        'photo_type',
        'photo_path'
    ];
}