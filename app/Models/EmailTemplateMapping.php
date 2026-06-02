<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplateMapping extends Model
{
    protected $fillable = [
        'template_id',
        'placeholder',
        'table_name',
        'column_name'
    ];
}