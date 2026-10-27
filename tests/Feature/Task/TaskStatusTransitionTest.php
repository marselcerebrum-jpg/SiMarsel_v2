<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $financeManager;

    private Account $staff;

    private Account $otherStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-10-27 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->financeManager = $this->account(Role::MANAGER, 'fin.manager', $this->finance);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);
    }

    // Akses

    public function test_guest_cannot_change_a_status(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->patchJson($this->url($task), $this->payload('in_progress'))->assertUnauthorized();
    }

    public function test_staff_cannot_move_a_card_they_are_not_pic_of(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->actingAs($this->otherStaff)
            ->patchJson($this->url($task), $this->payload('in_progress'))
            ->assertForbidden();

        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
    }

    public function test_manager_cannot_change_a_task_of_another_division(): void
    {
        $task = $this->task(TaskStatus::Backlog);

        $this->actingAs($this->financeManager)
            ->patchJson($this->url($task), $this->payload('todo'))
            ->assertForbidden();
    }

    public function test_unknown_task_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/tasks/999999/status', $this->payload('todo'))
            ->assertNotFound();
    }

    // Validasi input

    public function test_version_and_a_known_status_are_required(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), ['status' => 'archived'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'version' => 'Versi tugas wajib dikirim. Muat ulang detail tugas.',
                'status' => 'Status tujuan tidak valid.',
            ]);
    }

    // Matriks transisi

    public function test_manager_moves_backlog_to_todo(): void
    {
        $task = $this->task(TaskStatus::Backlog);

        $this->actingAs($this->manager)
            ->patchJson($this->url($task), $this->payload('todo'))
            ->assertOk()
            ->assertJsonPath('message', 'Status tugas berhasil diperbarui.')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.version', 2);
    }

    public function test_pic_cannot_move_backlog_to_todo(): void
    {
        $task = $this->task(TaskStatus::Backlog);

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), $this->payload('todo'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'status' => 'Hanya Admin atau Manajer divisi yang dapat mengubah status dari Back Log ke To Do.',
            ]);
    }

    public function test_backlog_task_without_pic_cannot_leave_backlog(): void
    {
        $task = $this->task(TaskStatus::Backlog, ['pic_id' => null]);

        $this->actingAs($this->manager)
            ->patchJson($this->url($task), $this->payload('todo'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id' => 'Tetapkan PIC sebelum memindahkan tugas dari Back Log.']);
    }

    public function test_pic_starts_the_work(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), $this->payload('in_progress'))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_manager_and_admin_cannot_start_the_work_for_the_pic(): void
    {
        $task = $this->task(TaskStatus::Todo);

        foreach ([$this->manager, $this->admin] as $account) {
            $this->actingAs($account)
                ->patchJson($this->url($task), $this->payload('in_progress'))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status' => 'Hanya PIC tugas yang dapat mengubah status dari To Do ke On Process.']);
        }
    }

    public function test_pic_submits_a_task_that_requires_qa_to_ready_for_qa(): void
    {
        $task = $this->task(TaskStatus::InProgress, ['requires_review' => true]);

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), $this->payload('done'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'status' => 'Status tidak dapat diubah dari On Process ke Done. Tugas ini wajib melalui QA sebelum Done.',
            ]);

        $this->patchJson($this->url($task), $this->payload('ready_for_qa'))
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_qa');
    }

    public function test_pic_finishes_a_task_without_qa_directly(): void
    {
        $task = $this->task(TaskStatus::InProgress, ['requires_review' => false]);

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), $this->payload('ready_for_qa'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Status tidak dapat diubah dari On Process ke Ready for QA.']);

        $this->patchJson($this->url($task), $this->payload('done'))
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_at', now()->toIso8601ZuluString());
    }

    public function test_review_is_run_by_admin_or_manager_only(): void
    {
        $task = $this->task(TaskStatus::ReadyForQa);

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), $this->payload('on_qa'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'status' => 'Hanya Admin atau Manajer divisi yang dapat mengubah status dari Ready for QA ke On QA.',
            ]);

        $this->actingAs($this->manager)
            ->patchJson($this->url($task), $this->payload('on_qa'))
            ->assertOk()
            ->assertJsonPath('data.status', 'on_qa');

        $this->actingAs($this->admin)
            ->patchJson($this->url($task), $this->payload('done', 2))
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_at', now()->toIso8601ZuluString());
    }

    public function test_review_sends_the_task_back_for_revision(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->manager)
            ->patchJson($this->url($task), $this->payload('in_progress'))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_moves_outside_the_matrix_are_rejected(): void
    {
        $cases = [
            [TaskStatus::Backlog, 'in_progress', $this->manager],
            [TaskStatus::Todo, 'backlog', $this->manager],
            [TaskStatus::Todo, 'done', $this->staff],
            [TaskStatus::InProgress, 'todo', $this->staff],
            [TaskStatus::ReadyForQa, 'done', $this->manager],
            [TaskStatus::ReadyForQa, 'in_progress', $this->staff],
            [TaskStatus::Done, 'in_progress', $this->admin],
        ];

        foreach ($cases as [$from, $to, $account]) {
            $task = $this->task($from);

            $this->actingAs($account)
                ->patchJson($this->url($task), $this->payload($to))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status' => "Status tidak dapat diubah dari {$from->label()} ke ".TaskStatus::from($to)->label().'.']);

            $this->assertSame($from, $task->fresh()->status);
        }
    }

    public function test_moving_to_the_current_status_is_rejected(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), $this->payload('todo'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Tugas sudah berstatus To Do.']);
    }

    // Sinkronisasi data

    public function test_status_change_is_recorded_in_history(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), $this->payload('in_progress'))
            ->assertOk()
            ->assertJsonPath('data.history.0.action', TaskActivity::STATUS_CHANGED)
            ->assertJsonPath('data.history.0.actor', ['id' => $this->staff->id, 'fullname' => 'mkt.staff'])
            ->assertJsonPath('data.history.0.changes', ['status' => ['old' => 'todo', 'new' => 'in_progress']]);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'in_progress', 'version' => 2]);
    }

    public function test_change_based_on_an_outdated_version_is_rejected(): void
    {
        $task = $this->task(TaskStatus::Todo);
        $task->forceFill(['version' => 3])->save();

        $this->actingAs($this->staff)
            ->patchJson($this->url($task), $this->payload('in_progress', 2))
            ->assertConflict()
            ->assertJsonPath('current_version', 3);

        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
        $this->assertDatabaseCount('task_activities', 0);
    }

    public function test_board_and_table_show_the_new_status_and_counts(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->actingAs($this->staff)->patchJson($this->url($task), $this->payload('in_progress'))->assertOk();

        $this->actingAs($this->manager)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('meta.status_counts.todo', 0)
            ->assertJsonPath('meta.status_counts.in_progress', 1);
    }

    // Opsi status di detail

    public function test_detail_lists_only_the_moves_the_account_may_make(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->actingAs($this->staff)
            ->getJson("/api/tasks/{$task->id}")
            ->assertJsonPath('data.next_statuses', [['value' => 'in_progress', 'label' => 'On Process']]);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$task->id}")
            ->assertJsonPath('data.next_statuses', []);

        $onQa = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$onQa->id}")
            ->assertJsonPath('data.next_statuses', [
                ['value' => 'done', 'label' => 'Done'],
                ['value' => 'in_progress', 'label' => 'On Process'],
            ]);
    }

    /**
     * @return array{version: int, status: string}
     */
    private function payload(string $status, int $version = 1): array
    {
        return ['version' => $version, 'status' => $status];
    }

    private function url(Task $task): string
    {
        return "/api/tasks/{$task->id}/status";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(TaskStatus $status, array $attributes = []): Task
    {
        return Task::factory()->create([
            'division_id' => $this->marketing->id,
            'pic_id' => $this->staff->id,
            'creator_id' => $this->manager->id,
            'status' => $status,
            'requires_review' => true,
            ...$attributes,
        ]);
    }

    private function account(string $role, string $username, ?Division $division): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $division?->id,
            'fullname' => $username,
            'username' => $username,
            'password' => 'Password123!',
        ]);
    }
}
