<?php

namespace App\Services;

use App\Models\TaskNotification;
use App\Events\TaskNotificationEvent;

class TaskNotificationService
{
/*
    public static function send($userId, $type, $title, $message, $taskId = null)
    {

        $notification = TaskNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'task_id' => $taskId
        ]);

        // Load relations
        $notification->load([
            'task.employee:id,name,picture',
            'task.manager:id,name,picture',
            'task.creator:id,name,picture'
        ]);

        broadcast(new TaskNotificationEvent($notification, $userId))->toOthers();

        return $notification;
    }
*/

    public static function send($userId, $type, $title, $message, $taskId = null)
    {

        // Do not send notification to the same user
        if (auth()->id() == $userId) {
            return null;
        }

        $notification = TaskNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'task_id' => $taskId
        ]);

        $notification->load([
            'task.employee:id,name,picture',
            'task.manager:id,name,picture',
            'task.creator:id,name,picture'
        ]);

        broadcast(new TaskNotificationEvent($notification, $userId))->toOthers();

        return $notification;
    }
}