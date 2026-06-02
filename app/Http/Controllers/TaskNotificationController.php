<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskRemark;
use App\Models\TaskUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\TaskNotificationService;
use App\Constants\NotificationTypes;
use App\Models\TaskNotification;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;


class TaskNotificationController extends Controller
{

    /*
    |--------------------------------------------------------------------------
    | Get User Notifications
    |--------------------------------------------------------------------------
    */

public function index($userId)
{

    $notifications = TaskNotification::with([
        'task.employee:id,name,picture',
        'task.manager:id,name,picture',
        'task.creator:id,name,picture'
    ])
    ->where('user_id', $userId)
    ->where('is_read', 0)   // 👈 only unread
    ->latest()
    ->limit(50)
    ->get()
    ->map(function ($notification) {

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'is_read' => $notification->is_read,
            'created_at' => $notification->created_at,

            'task' => $notification->task,

            'user_image' => optional($notification->task?->employee)->picture
        ];
    });

    return response()->json([
        'status' => true,
        'data' => $notifications
    ]);
}

    /*
    |--------------------------------------------------------------------------
    | Mark Notification As Read
    |--------------------------------------------------------------------------
    */

    public function markAsRead($id)
    {

        $notification = TaskNotification::find($id);

        if(!$notification){
            return response()->json([
                'status'=>false,
                'message'=>'Notification not found'
            ],404);
        }

        $notification->update([
            'is_read'=>1
        ]);

        return response()->json([
            'status'=>true,
            'message'=>'Notification marked as read'
        ]);

    }

    /*
    |--------------------------------------------------------------------------
    | Mark All As Read
    |--------------------------------------------------------------------------
    */

    public function markAllRead($userId)
    {

        TaskNotification::where('user_id',$userId)
            ->update(['is_read'=>1]);

        return response()->json([
            'status'=>true,
            'message'=>'All notifications marked as read'
        ]);

    }
    public function unreadCount($userId)
    {
    $count = TaskNotification::where('user_id',$userId)
        ->where('is_read',0)
        ->count();
    
      return response()->json([
        'status' => true,
        'count' => $count
    ]);
}
    public function saveToken(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'token' => 'required'
        ]);
    
        DB::table('user_devices')->updateOrInsert(
            [
                'user_id' => $request->user_id,
                'token' => $request->token
            ],
            [
                'platform' => $request->platform ?? 'web',
                'updated_at' => now()
            ]
        );
    
        return response()->json(['status' => true]);
    }
    function sendPushNotification($tokens, $title, $body, $data = [])
    {
        $factory = (new Factory)->withServiceAccount(storage_path('app/firebase/serviceAccount.json'));
        $messaging = $factory->createMessaging();
    
        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($data);
    
        foreach ($tokens as $token) {
            $messaging->send($message->withChangedTarget('token', $token));
        }
    }
}