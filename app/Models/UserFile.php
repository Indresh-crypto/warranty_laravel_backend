<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFile extends Model
{
    protected $table = 'user_files';

    protected $fillable = [
        'email', 'file_url', 'flag'
        ];

}