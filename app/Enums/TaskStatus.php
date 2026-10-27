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

    /**
     * Statuses a new task may start in: Back Log when it is not ready yet, To Do when it is.
     *
     * @return array<int, self>
     */
    public static function initial(): array
    {
        return [self::Backlog, self::Todo];
    }

    /**
     * Statuses this one may move to. A task that requires QA reaches Done only through Ready for QA and On QA;
     * one that does not goes from On Process straight to Done. On QA goes back to On Process for revision.
     *
     * @return array<int, self>
     */
    public function nextStatuses(bool $requiresReview): array
    {
        return match ($this) {
            self::Backlog => [self::Todo],
            self::Todo => [self::InProgress],
            self::InProgress => [$requiresReview ? self::ReadyForQa : self::Done],
            self::ReadyForQa => [self::OnQa],
            self::OnQa => [self::Done, self::InProgress],
            self::Done => [],
        };
    }

    public function canMoveTo(self $status, bool $requiresReview): bool
    {
        return in_array($status, $this->nextStatuses($requiresReview), true);
    }

    /**
     * Whether moving out of this status is the PIC's step (starting and submitting the work).
     * Every other move is made by Admin or the Manager of the task's division.
     */
    public function movedByPic(): bool
    {
        return $this === self::Todo || $this === self::InProgress;
    }

    public function inQa(): bool
    {
        return $this === self::ReadyForQa || $this === self::OnQa;
    }

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
