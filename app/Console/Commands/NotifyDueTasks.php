<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Task;
use App\Models\TaskRemark;
use App\Models\TaskUser;

class NotifyDueTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify-due-tasks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
  public function handle()
    {
    
        $tasks = Task::whereDate('due_date',now()->toDateString())
            ->where('status','!=',3)
            ->get();
    
        foreach($tasks as $task){
    
            TaskNotificationService::send(
                $task->employee_id,
                'task_due_today',
                'Task Due Today',
                'Task due today: '.$task->title,
                $task->id
            );
        }
    
    }
}
