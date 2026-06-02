<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TaskChatMessage extends Model
{

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'message',
        'attachment',
        'read_at',
        'type',
        'thumbnail',
        'file_size',
        'task_id',
        'meta'
    ];

    public function sender()
    {
        return $this->belongsTo(TaskUser::class,'sender_id');
    }
    
    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }


}