<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class TaskNotificationEvent implements ShouldBroadcastNow
{
    use SerializesModels;

    public $notification;
    public $userId;

    public function __construct($notification, $userId)
    {
        $this->notification = $notification;
        $this->userId = $userId;
    }

    public function broadcastOn()
    {
        return new Channel('user.' . $this->userId);
    }

    public function broadcastAs()
    {
        return 'task.notification';
    }

    public function broadcastWith()
    {
        $task = $this->notification->task;
    
        return [
            'notification' => [
                'id' => $this->notification->id,
                'type' => $this->notification->type,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'is_read' => $this->notification->is_read,
                'created_at' => $this->notification->created_at,
    
                'task' => $task,
    
                'user_image' => optional($task?->employee)->picture
            ]
        ];
    }
    }