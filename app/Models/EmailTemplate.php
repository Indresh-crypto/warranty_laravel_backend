<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['name', 'subject', 'body', 'status'];

    public function mappings()
    {
        return $this->hasMany(EmailTemplateMapping::class, 'template_id');
    }
}