<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case ReadyReview = 'ready_review';
    case OnReview = 'on_review';
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
     * Statuses this one may move to. A task that requires QA reaches Done only through Ready Review and On Review;
     * one that does not goes from On Process straight to Done. On Review goes back to On Process for revision,
     * and a Done task stays done.
     *
     * @return array<int, self>
     */
    public function nextStatuses(bool $requiresReview): array
    {
        return match ($this) {
            self::Backlog => [self::Todo],
            self::Todo => [self::InProgress],
            self::InProgress => [$requiresReview ? self::ReadyReview : self::Done],
            self::ReadyReview => [self::OnReview],
            self::OnReview => [self::InProgress, self::Done],
            self::Done => [],
        };
    }

    public function canMoveTo(self $status, bool $requiresReview): bool
    {
        return in_array($status, $this->nextStatuses($requiresReview), true);
    }

    /**
     * Whether moving out of this status is the PIC's step: starting the work, then handing it to QA or
     * finishing it. Every other move is made by Admin or the Manager of the task's division.
     */
    public function movedByPic(): bool
    {
        return $this === self::Todo || $this === self::InProgress;
    }

    /**
     * Whether leaving this status is a QA decision, which is made through a review (Approve or Request
     * Revision) so it is recorded with its reason, not through a plain status change.
     */
    public function decidedByReview(): bool
    {
        return $this === self::OnReview;
    }

    public function inQa(): bool
    {
        return $this === self::ReadyReview || $this === self::OnReview;
    }

    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Back Log',
            self::Todo => 'To Do',
            self::InProgress => 'On Process',
            self::ReadyReview => 'Ready Review',
            self::OnReview => 'On Review',
            self::Done => 'Done',
        };
    }
}
