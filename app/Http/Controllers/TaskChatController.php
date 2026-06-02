<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TaskChatConversation;
use App\Models\TaskChatMessage;
use App\Models\TaskUser;
use App\Events\TaskChatMessageSent;
use App\Events\TaskChatMessageRead;
use App\Events\TaskNewChatEvent;

use App\Events\TaskChatTyping;
use Illuminate\Http\Request;
use App\Models\TaskTeam;
use App\Models\TaskChatParticipant;
use App\Events\TaskConversationUpdatedEvent;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

use Illuminate\Support\Facades\Validator;

use App\Models\Task;
use DB;
use App\Jobs\SendPushNotificationJob;

class TaskChatController extends Controller
{

    /*
    |--------------------------------------------------------------------------
    | Get Conversations
    |--------------------------------------------------------------------------
    */
    public function conversations($userId)
    {
        $conversations = TaskChatConversation::whereHas('participants', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->with(['messages' => function ($q) {
                $q->latest()->limit(1);
            }])
            ->get()
            ->map(function ($chat) use ($userId) {

                $lastMessage = $chat->messages->first();

                return [
                    'id' => $chat->id,
                    'name' => $chat->name ?? 'Chat',
                    'last_message' => optional($lastMessage)->message,
                    'last_time' => optional($lastMessage)->created_at,

                    'unread' => TaskChatMessage::where('conversation_id', $chat->id)
                        ->whereNull('read_at')
                        ->where('sender_id', '!=', $userId)
                        ->count()
                ];
            });

        return response()->json($conversations);
    }


    /*
    |--------------------------------------------------------------------------
    | Get Messages (WITH PAGINATION)
    |--------------------------------------------------------------------------
    */


    public function messages(Request $request, $conversationId)
    {
        $perPage = $request->get('per_page', 50);
    
        $messages = TaskChatMessage::where('conversation_id', $conversationId)
            ->with([
                'sender:id,name,picture,last_seen_at',
                'task:id,title,status,priority,due_date'
            ])
            ->latest() 
            ->paginate($perPage);
    
        $messages->setCollection(
            $messages->getCollection()->reverse()->values()
        );
    
        return response()->json($messages);
    }

    /*
    |--------------------------------------------------------------------------
    | Send Message
    |--------------------------------------------------------------------------
    */

public function send(Request $request)
{
    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    */
    $rules = [
        'conversation_id' => 'nullable|integer',
        'sender_id' => 'required|integer',
        'receiver_id' => 'nullable|integer',
        'team_id' => 'nullable|integer|exists:task_teams,id',
        'message' => 'nullable|string',
        'type' => 'required|string|in:text,image,file,audio,video,location,task',
        'task_id' => 'required_if:type,task|integer|exists:tasks,id',
        'lat' => 'required_if:type,location',
        'lng' => 'required_if:type,location',
        'address' => 'required_if:type,location',
    ];

    if ($request->type == 'image') {
        $rules['file'] = 'required|file|mimes:jpg,jpeg,png,webp,gif|max:' . (5 * 1024);
    }
    if ($request->type == 'audio') {
        $rules['file'] = 'required|file|mimes:mp3,wav,m4a,aac,webm|max:' . (10 * 1024);
    }
    if ($request->type == 'video') {
        $rules['file'] = 'required|file|mimes:mp4,mov,avi,mkv|max:' . (50 * 1024);
    }
    if ($request->type == 'file') {
        $rules['file'] = 'required|file|max:' . (20 * 1024);
    }

    $validator = Validator::make($request->all(), $rules);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first()
        ], 422);
    }

    if ($request->type == 'text' && empty($request->message)) {
        return response()->json([
            'status' => false,
            'message' => 'Message text required'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Conversation Find Logic (FINAL CORRECT ORDER)
    |--------------------------------------------------------------------------
    */
    $conversation = null;

    // 1. Team Conversation FIRST
    if ($request->team_id) {
        $conversation = TaskChatConversation::where('team_id', $request->team_id)
            ->where('is_group', 1)
            ->first();
    }

    // 2. If conversation_id provided
    if (!$conversation && $request->conversation_id) {
        $conversation = TaskChatConversation::find($request->conversation_id);
    }

    // 3. Private Conversation
    if (
        !$conversation &&
        $request->receiver_id &&
        !$request->team_id
    ) {
        $conversation = TaskChatConversation::where('is_group', 0)
            ->whereHas('participants', function ($q) use ($request) {
                $q->whereIn('user_id', [$request->sender_id, $request->receiver_id]);
            }, '=', 2)
            ->first();

        // Create new private conversation
        if (!$conversation) {
            $conversation = TaskChatConversation::create([
                'name' => null,
                'is_group' => 0,
                'created_by' => $request->sender_id
            ]);

            TaskChatParticipant::insert([
                ['conversation_id' => $conversation->id, 'user_id' => $request->sender_id],
                ['conversation_id' => $conversation->id, 'user_id' => $request->receiver_id]
            ]);

            broadcast(new TaskNewChatEvent($conversation->id, $request->receiver_id));
        }
    }

    if (!$conversation) {
        return response()->json([
            'status' => false,
            'message' => 'Conversation not found'
        ], 400);
    }

    $conversationId = $conversation->id;

    /*
    |--------------------------------------------------------------------------
    | Upload File
    |--------------------------------------------------------------------------
    */
    $attachment = null;
    $thumbnail = null;

    if ($request->hasFile('file')) {

        if (!file_exists(storage_path('app/public/chat'))) {
            mkdir(storage_path('app/public/chat'), 0755, true);
        }

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $filename = time().'_'.uniqid().'.'.$extension;

        $path = storage_path('app/public/chat/'.$filename);

        if (in_array(strtolower($extension), ['jpg','jpeg','png','webp'])) {

            $thumbName = 'thumb_'.$filename;
            $thumbPath = storage_path('app/public/chat/'.$thumbName);

            $manager = new ImageManager(new Driver());
            $image = $manager->read($file);

            $image->scale(width: 1280);
            $image->toJpeg(70)->save($path);

            $image->cover(300, 300);
            $image->toJpeg(60)->save($thumbPath);

            $attachment = 'chat/'.$filename;
            $thumbnail = 'chat/'.$thumbName;

        } else {
            $file->storeAs('chat', $filename, 'public');
            $attachment = 'chat/'.$filename;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Message Types
    |--------------------------------------------------------------------------
    */
    if ($request->type == 'location') {

        $message = TaskChatMessage::create([
            'conversation_id' => $conversationId,
            'sender_id' => $request->sender_id,
            'type' => 'location',
            'meta' => json_encode([
                'lat' => $request->lat,
                'lng' => $request->lng,
                'address' => $request->address
            ])
        ]);

    } elseif ($request->type == 'audio') {

        $message = TaskChatMessage::create([
            'conversation_id' => $conversationId,
            'sender_id' => $request->sender_id,
            'attachment' => $attachment,
            'type' => 'audio'
        ]);

    } elseif ($request->type == 'video') {

        $message = TaskChatMessage::create([
            'conversation_id' => $conversationId,
            'sender_id' => $request->sender_id,
            'attachment' => $attachment,
            'thumbnail' => $thumbnail,
            'type' => 'video'
        ]);

    } elseif ($request->type == 'task') {

        $message = TaskChatMessage::create([
            'conversation_id' => $conversationId,
            'sender_id' => $request->sender_id,
            'task_id' => $request->task_id,
            'message' => $request->message,
            'type' => 'task'
        ]);

    } else {

        $message = TaskChatMessage::create([
            'conversation_id' => $conversationId,
            'sender_id' => $request->sender_id,
            'message' => $request->message,
            'attachment' => $attachment,
            'thumbnail' => $thumbnail,
            'type' => $request->type
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Load Sender + Task
    |--------------------------------------------------------------------------
    */
    $message->load([
        'sender:id,name,picture,last_seen_at',
        'task:id,title,status,priority,due_date'
    ]);

    /*
    |--------------------------------------------------------------------------
    | Update Conversation Last Message
    |--------------------------------------------------------------------------
    */
    TaskChatConversation::where('id', $conversationId)->update([
        'last_message' => $message->message,
        'last_message_type' => $message->type,
        'last_message_at' => $message->created_at,
        'last_sender_id' => $message->sender_id,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Broadcast Message
    |--------------------------------------------------------------------------
    */
    broadcast(new TaskChatMessageSent($message, $conversationId));

    /*
    |--------------------------------------------------------------------------
    | Update Conversation List Realtime
    |--------------------------------------------------------------------------
    */
    $participants = TaskChatParticipant::where('conversation_id', $conversationId)
        ->pluck('user_id');

    foreach ($participants as $uid) {

       // Get unread count for THIS user
            $unreadCount = TaskChatMessage::where('conversation_id', $conversationId)
                ->whereNull('read_at')
                ->where('sender_id', '!=', $uid)
                ->count();
            
            // Format last message text like API
            $lastMessageText = null;
            
            switch ($message->type) {
                case 'text': $lastMessageText = $message->message; break;
                case 'image': $lastMessageText = '📷 Image'; break;
                case 'video': $lastMessageText = '🎥 Video'; break;
                case 'audio': $lastMessageText = '🎤 Audio'; break;
                case 'location': $lastMessageText = '📍 Location'; break;
                case 'file': $lastMessageText = '📄 File'; break;
                case 'task': $lastMessageText = '📌 Task Shared'; break;
            }

        // Get receiver user details
       $user = TaskUser::select('id','name','picture')->find($uid);
        
        if (!$user) {
            continue; // skip invalid user (IMPORTANT)
        }
        
       $conversationData = [
            'conversation_id' => $conversationId,
            'user_id' => $uid,
        
            // ✅ SAME AS teamChatUsers API
            'name' => $user->name,
            'picture' => $user->picture,
        
            'last_message' => $lastMessageText,
            'last_time' => $message->created_at,
            'unread_count' => $unreadCount,
        
            // optional (safe extras)
            'sender_id' => $message->sender_id,
            'is_group' => $conversation->is_group,
            'team_id' => $conversation->team_id,
        ];

        broadcast(new TaskConversationUpdatedEvent($conversationData));
            }
        
            return response()->json([
                'conversation_id' => $conversationId,
                'message' => $message
            ]);
    }
    /*
    |--------------------------------------------------------------------------
    | Read Messages (✓✓)
    |--------------------------------------------------------------------------
    */
    public function read($conversationId, $userId)
    {
        TaskChatMessage::where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now()
            ]);

        broadcast(new TaskChatMessageRead($conversationId, $userId))->toOthers();

        return response()->json([
            'status' => true
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Typing Event
    |--------------------------------------------------------------------------
    */
    public function typing(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|integer',
            'user_id' => 'required|integer'
        ]);

        broadcast(new TaskChatTyping(
            $request->conversation_id,
            $request->user_id
        ))->toOthers();

        return response()->json(['status' => true]);
    }


    /*
    |--------------------------------------------------------------------------
    | Update Last Seen (ONLINE/OFFLINE)
    |--------------------------------------------------------------------------
    */

    public function updateLastSeen(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);
    
        TaskUser::where('id', $request->user_id)->update([
            'last_seen_at' => now()
        ]);
    
        return response()->json(['status' => true]);
    }

    
    public function createConversation(Request $request)
    {
        $conversation = TaskChatConversation::whereHas('participants', function ($q) use ($request) {
            $q->whereIn('user_id', [$request->user_id, $request->other_user_id]);
        }, '=', 2)->first();
    
        if (!$conversation) {
            $conversation = TaskChatConversation::create([
                'name' => null
            ]);
    
            $conversation->participants()->createMany([
                ['user_id' => $request->user_id],
                ['user_id' => $request->other_user_id],
            ]);
        }
    
        return response()->json($conversation);
    }



    public function teamChatUsers(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:task_users,id',
            'role'    => 'required|integer'
        ]);
    
        $userId = $request->user_id;
        $role   = $request->role;
    
        /*
        |--------------------------------------------------------------------------
        | Get Users Based On Role
        |--------------------------------------------------------------------------
        */
        if ($role == 4) {
            $user = TaskUser::find($userId);
    
            $users = TaskUser::where(function ($q) use ($user) {
                    $q->where('id', $user->report_to)
                      ->orWhere('report_to', $user->report_to);
                })
                ->where('id', '!=', $userId)
                ->get();
        }
    
        elseif ($role == 3) {
            $users = TaskUser::where('report_to', $userId)->get();
        }
    
        else {
            $users = collect();
        }
    
        if ($users->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No users found',
                'data' => []
            ]);
        }
    
        $userIds = $users->pluck('id')->toArray();
        $allUserIds = array_merge([$userId], $userIds);
    
        /*
        |--------------------------------------------------------------------------
        | Get Conversations For These Users
        |--------------------------------------------------------------------------
        */
       
       $conversations = TaskChatConversation::where('is_group', 0)
        ->whereHas('participants', function ($q) use ($allUserIds) {
            $q->whereIn('user_id', $allUserIds);
        })
        ->with('participants')
        ->get();
        
        /*
        |--------------------------------------------------------------------------
        | Map conversation per user
        |--------------------------------------------------------------------------
        */
        $conversationMap = [];
    
        foreach ($conversations as $conv) {
            $participantIds = $conv->participants->pluck('user_id')->toArray();
    
            if (in_array($userId, $participantIds)) {
                foreach ($participantIds as $pid) {
                    if ($pid != $userId) {
                        $conversationMap[$pid] = $conv->id;
                    }
                }
            }
        }
    
        $conversationIds = array_values($conversationMap);
    
        /*
        |--------------------------------------------------------------------------
        | Get Last Messages
        |--------------------------------------------------------------------------
        */
        $lastMessages = TaskChatMessage::whereIn('conversation_id', $conversationIds)
            ->orderBy('id', 'desc')
            ->get()
            ->groupBy('conversation_id')
            ->map(function ($msgs) {
                return $msgs->first();
            });
    
        /*
        |--------------------------------------------------------------------------
        | Get Unread Counts
        |--------------------------------------------------------------------------
        */
        $unreadCounts = TaskChatMessage::whereIn('conversation_id', $conversationIds)
            ->whereNull('read_at')
            ->where('sender_id', '!=', $userId)
            ->selectRaw('conversation_id, COUNT(*) as total')
            ->groupBy('conversation_id')
            ->pluck('total', 'conversation_id');
    
        /*
        |--------------------------------------------------------------------------
        | Build Final User List
        |--------------------------------------------------------------------------
        */
        $finalUsers = $users->map(function ($user) use ($conversationMap, $lastMessages, $unreadCounts) {
    
            $conversationId = $conversationMap[$user->id] ?? null;
            $lastMessage = $conversationId ? ($lastMessages[$conversationId] ?? null) : null;
    
            $lastMessageText = null;
    
            if ($lastMessage) {
                switch ($lastMessage->type) {
                    case 'text': $lastMessageText = $lastMessage->message; break;
                    case 'image': $lastMessageText = '📷 Image'; break;
                    case 'video': $lastMessageText = '🎥 Video'; break;
                    case 'audio': $lastMessageText = '🎤 Audio'; break;
                    case 'location': $lastMessageText = '📍 Location'; break;
                    case 'file': $lastMessageText = '📄 File'; break;
                    case 'task': $lastMessageText = '📌 Task Shared'; break;
                }
            }
    
            return [
                'user_id' => $user->id,
                'name'    => $user->name,
                'picture' => $user->picture,
                'role'    => $user->role,
                'conversation_id' => $conversationId,
                'last_message' => $lastMessageText,
                'last_time'    => optional($lastMessage)->created_at,
                'unread_count' => $conversationId ? ($unreadCounts[$conversationId] ?? 0) : 0,
                'is_online' => $user->last_seen_at 
                    ? now()->diffInSeconds($user->last_seen_at) < 60
                    : false,
                'last_seen' => $user->last_seen_at
            ];
        });
    
        /*
        |--------------------------------------------------------------------------
        | Sort By Last Message Time
        |--------------------------------------------------------------------------
        */
        $finalUsers = $finalUsers->sortByDesc('last_time')->values();
    
        return response()->json([
            'status' => true,
            'message' => 'Team Chat Users Retrieved',
            'data' => $finalUsers
        ]);
    }

    public function teamChatList(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:task_users,id'
        ]);
    
        $userId = $request->user_id;
    
        /*
        |--------------------------------------------------------------------------
        | Get Teams where user exists
        |--------------------------------------------------------------------------
        */
    
        $teams = TaskTeam::where(function ($q) use ($userId) {
    
                //  If manager
                $q->where('manager_id', $userId);
    
            })->orWhereHas('members', function ($q) use ($userId) {
    
                //  If member
                $q->where('user_id', $userId);
    
            })
            ->with(['manager', 'members'])
            ->get();
    
        /*
        |--------------------------------------------------------------------------
        | Map Teams with Chat Data
        |--------------------------------------------------------------------------
        */
    
        $data = $teams->map(function ($team) use ($userId) {
    
            // 🔹 Get ALL user ids in team (manager + members)
            $memberIds = $team->members->pluck('id')->toArray();
            $memberIds[] = $team->manager_id;
    
            /*
            |--------------------------------------------------------------------------
            | Find Group Conversation
            |--------------------------------------------------------------------------
            */
   
                
                $conversation = TaskChatConversation::where('team_id', $team->id)
                ->where('is_group', 1)
                ->first();
    
            $lastMessage = null;
            $unreadCount = 0;
    
            if ($conversation) {
    
                $lastMessage = TaskChatMessage::where('conversation_id', $conversation->id)
                                    ->latest()
                                    ->first();
    
                $unreadCount = TaskChatMessage::where('conversation_id', $conversation->id)
                                    ->whereNull('read_at')
                                    ->where('sender_id', '!=', $userId)
                                    ->count();
            }
    
            return [
                'team_id' => $team->id,
                'team_name' => $team->name,
    
                'conversation_id' => optional($conversation)->id,
    
                // 👇 Members (including manager)
                'members' => collect($team->members)
                    ->push($team->manager)
                    ->unique('id')
                    ->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'picture' => $user->picture
                        ];
                    })->values(),
    
                'last_message' => optional($lastMessage)->message,
                'last_time'    => optional($lastMessage)->created_at,
    
                'unread_count' => $unreadCount
            ];
        });
    
        return response()->json([
            'status' => true,
            'message' => 'Team Chats Retrieved',
            'data' => $data
        ]);
    }
    
        

public function createTeamConversation($teamId)
{
    $team = TaskTeam::with(['manager', 'members'])->findOrFail($teamId);

    // Check existing conversation
    $conversation = TaskChatConversation::where('team_id', $teamId)
        ->where('is_group', 1)
        ->first();

    if (!$conversation) {
        $conversation = TaskChatConversation::create([
            'name' => $team->name,
            'is_group' => 1,
            'created_by' => $team->manager_id,
            'team_id' => $teamId
        ]);

        $memberIds = $team->members->pluck('id')->toArray();
        $memberIds[] = $team->manager_id;

        foreach ($memberIds as $userId) {
            TaskChatParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId
            ]);
        }
    }

    return response()->json([
        'conversation_id' => $conversation->id
    ]);
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
    

    
    public function searchTasks(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:task_users,id',
            'search' => 'nullable|string|max:100'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }
    
        $tasks = Task::where(function ($q) use ($request) {
                $q->where('employee_id', $request->user_id)
                  ->orWhere('manager_id', $request->user_id);
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%');
            })
            ->select(
                'id',
                'title',
                'status',
                'priority',
                'due_date'
            )
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();
    
        return response()->json([
            'status' => true,
            'data' => $tasks
        ]);
    }

    public function truncateChatTables()
    {
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    
            DB::table('task_chat_messages')->truncate();
            DB::table('task_chat_participants')->truncate();
            DB::table('task_chat_conversations')->truncate();
    
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    
            return response()->json([
                'status' => true,
                'message' => 'All chat tables truncated successfully'
            ]);
    
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}