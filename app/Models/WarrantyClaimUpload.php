<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyClaimUpload extends Model
{
    protected $table = 'warranty_claim_uploads';

    protected $fillable = [
        'w_customer_id',
        'photo_type',
        'photo_path'
    ];
}