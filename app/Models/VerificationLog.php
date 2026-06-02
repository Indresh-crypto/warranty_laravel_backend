<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationLog extends Model
{
    protected $table = 'verification_logs';

    protected $fillable = [
        'org_code',
        'reference_id',
        'type',
        'request_payload',
        'response_payload',
        'status',
        'message'
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'status' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | CONSTANTS (Clean code usage)
    |--------------------------------------------------------------------------
    */

    const TYPE_PAN  = 'pan';
    const TYPE_GST  = 'gst';
    const TYPE_BANK = 'bank';

    /*
    |--------------------------------------------------------------------------
    | SCOPES (Helpful for filtering)
    |--------------------------------------------------------------------------
    */

    public function scopeSuccess($query)
    {
        return $query->where('status', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', false);
    }

    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function isSuccess()
    {
        return $this->status === true;
    }

    public function isFailed()
    {
        return !$this->status;
    }

    /*
    |--------------------------------------------------------------------------
    | RELATION (Optional - if you want)
    |--------------------------------------------------------------------------
    */

    public function org()
    {
        return $this->belongsTo(OrgUsersMaster::class, 'org_code', 'org_code');
    }
}