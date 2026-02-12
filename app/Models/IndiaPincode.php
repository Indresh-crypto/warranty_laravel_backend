<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IndiaPincode extends Model   // <-- FIXED casing
{

        protected $fillable = [
            
            'pincode',
            'district',
            'state',
            'state_in',
            'district_in'

            ];
}