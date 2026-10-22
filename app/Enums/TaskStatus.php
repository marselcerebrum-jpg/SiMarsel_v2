<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case ReadyForQa = 'ready_for_qa';
    case OnQa = 'on_qa';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Back Log',
            self::Todo => 'To Do',
            self::InProgress => 'On Process',
            self::ReadyForQa => 'Ready for QA',
            self::OnQa => 'On QA',
            self::Done => 'Done',
        };
    }
}
