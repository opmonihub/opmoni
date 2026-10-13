<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Process;

class TaskProgress
{
    /**
     * @return array{total: int, done: int, dismissed: int, open: int, ratio: float}
     */
    public function for(Process $process): array
    {
        $total = $process->tasks->count();
        $done = 0;
        $dismissed = 0;

        foreach ($process->tasks as $task) {
            $status = $task->status instanceof TaskStatus ? $task->status->value : (string) $task->status;

            if ($status === TaskStatus::Done->value) {
                $done++;
            } elseif ($status === TaskStatus::Dismissed->value) {
                $dismissed++;
            }
        }

        $open = $total - $done - $dismissed;

        return [
            'total' => $total,
            'done' => $done,
            'dismissed' => $dismissed,
            'open' => $open,
            'ratio' => $total > 0 ? round($done / $total, 2) : 0.0,
        ];
    }
}
