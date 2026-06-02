<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class TaskSendMessageController extends Controller
{
  
  
public function sendMessage(Request $request)
{
    $validator = Validator::make($request->all(),[
        'conversation_id'=>'required',
        'sender_id'=>'required',
        'message'=>'nullable|string',
        'file'=>'nullable|file|max:10000'
    ]);

    if($validator->fails()){
        return response()->json([
            'status'=>false,
            'message'=>$validator->errors()
        ]);
    }

    $filePath = null;

    if($request->hasFile('file')){

        $name = time().'_chat.'.$request->file('file')->extension();

        $request->file('file')->move(public_path('chat_files'),$name);

        $filePath = 'chat_files/'.$name;
    }

    $message = ChatMessage::create([
        'conversation_id'=>$request->conversation_id,
        'sender_id'=>$request->sender_id,
        'message'=>$request->message,
        'attachment'=>$filePath,
        'type'=>$filePath ? 'file':'text'
    ]);

    broadcast(new ChatMessageEvent($message,$request->conversation_id))->toOthers();

    return response()->json([
        'status'=>true,
        'data'=>$message
    ]);
}
}