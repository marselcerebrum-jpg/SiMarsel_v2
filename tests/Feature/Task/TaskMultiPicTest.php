<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Exceptions\PrimaryDivisionCollaboratorException;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Services\TaskAssignmentBackfillService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * STEAM-270: several active PICs per task, PICs across divisions, and collaborating divisions.
 */
class TaskMultiPicTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $staff;

    private Account $otherStaff;

    private Account $financeStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-10-23 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);
        $this->financeStaff = $this->account(Role::EMPLOYEE, 'fin.staff', $this->finance);
    }

    // Tidak ada duplikasi Task Card

    public function test_a_task_with_several_pics_is_listed_and_counted_once(): void
    {
        $shared = $this->task(['title' => 'Bersama', 'status' => TaskStatus::InProgress], [$this->staff, $this->otherStaff, $this->financeStaff]);
        $this->task(['title' => 'Sendiri', 'status' => TaskStatus::Todo], [$this->otherStaff]);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.status_counts.in_progress', 1)
            ->assertJsonPath('meta.status_counts.todo', 1);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks?per_page=1&page=1&sort=pic')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $shared->id)
            ->assertJsonCount(3, 'data.0.pics');

        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?scope=report')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 2)
            ->assertJsonPath('data.metrics.on_process', 1);
    }

    public function test_sorting_by_pic_uses_the_first_active_pic_by_name(): void
    {
        $zeta = $this->account(Role::EMPLOYEE, 'zeta', $this->marketing);
        $alpha = $this->account(Role::EMPLOYEE, 'alpha', $this->marketing);
        $late = $this->task(['title' => 'Z'], [$zeta]);
        $early = $this->task(['title' => 'A'], [$zeta, $alpha]);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks?sort=pic&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$early->id, $late->id]);
    }

    // my_tasks dan all_tasks

    public function test_my_tasks_lists_only_tasks_the_account_is_an_active_pic_of(): void
    {
        $mine = $this->task(['title' => 'Milik saya'], [$this->staff, $this->otherStaff]);
        $released = $this->task(['title' => 'Sudah dilepas'], [$this->otherStaff]);
        $released->assignments()->create([
            'account_id' => $this->manager->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now()->subDay(),
            'unassigned_by' => $this->admin->id, 'unassigned_at' => now(),
        ]);
        $this->task(['title' => 'Divisi saya, bukan PIC'], [$this->otherStaff]);

        $this->actingAs($this->otherStaff)
            ->getJson('/api/tasks?scope=my_tasks')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($this->staff)
            ->getJson('/api/tasks?scope=my_tasks')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mine->id]);

        $this->actingAs($this->manager)
            ->getJson('/api/tasks?scope=my_tasks')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->manager)
            ->getJson('/api/tasks?scope=all_tasks')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_scope_must_be_my_tasks_or_all_tasks(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/tasks?scope=team')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scope' => 'Cakupan tidak valid.']);
    }

    // Workflow bersama

    public function test_every_active_pic_runs_the_shared_workflow_up_to_ready_review(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo, 'requires_review' => true, 'evidence_required' => false], [$this->staff, $this->financeStaff]);

        $this->actingAs($this->staff)
            ->patchJson("/api/tasks/{$task->id}/status", ['version' => 1, 'status' => 'in_progress'])
            ->assertOk();

        $this->actingAs($this->financeStaff)
            ->patchJson("/api/tasks/{$task->id}/status", ['version' => 2, 'status' => 'ready_review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_review');

        $this->actingAs($this->otherStaff)
            ->patchJson("/api/tasks/{$task->id}/status", ['version' => 3, 'status' => 'on_review'])
            ->assertForbidden();
    }

    public function test_every_active_pic_may_send_evidence(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], [$this->staff, $this->financeStaff]);

        foreach ([$this->staff, $this->financeStaff] as $pic) {
            $this->actingAs($pic)
                ->postJson("/api/tasks/{$task->id}/evidences", ['url' => "https://example.com/{$pic->username}"])
                ->assertCreated();
        }

        $this->actingAs($this->otherStaff)
            ->postJson("/api/tasks/{$task->id}/evidences", ['url' => 'https://example.com/lain'])
            ->assertForbidden();
    }

    // Divisi kolaborator

    public function test_the_owning_division_cannot_be_a_collaborating_division(): void
    {
        $task = $this->task();
        $task->collaboratingDivisions()->attach($this->finance->id, ['added_by' => $this->manager->id]);

        $this->assertDatabaseHas('task_divisions', ['task_id' => $task->id, 'division_id' => $this->finance->id, 'added_by' => $this->manager->id, 'created_at' => '2026-10-23 09:00:00']);

        $this->expectException(PrimaryDivisionCollaboratorException::class);
        $task->collaboratingDivisions()->attach($this->marketing->id, ['added_by' => $this->manager->id]);
    }

    public function test_a_pic_from_another_division_does_not_need_a_collaborating_division(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], [$this->financeStaff]);

        $this->assertSame(0, $task->collaboratingDivisions()->count());
        $this->actingAs($this->financeStaff)->getJson("/api/tasks/{$task->id}")->assertOk();
    }

    // Backfill

    public function test_backfill_adds_missing_assignments_once_and_never_restores_a_released_pic(): void
    {
        $legacy = $this->legacyTask($this->staff);
        $migrated = $this->task([], [$this->otherStaff]);
        DB::table('tasks')->where('id', $migrated->id)->update(['pic_id' => $this->staff->id]);
        $released = $this->task(['status' => TaskStatus::Backlog]);
        $released->assignments()->create([
            'account_id' => $this->staff->id, 'assigned_by' => $this->manager->id, 'assigned_at' => now()->subDay(),
            'unassigned_by' => $this->manager->id, 'unassigned_at' => now(),
        ]);
        DB::table('tasks')->where('id', $released->id)->update(['pic_id' => $this->staff->id]);

        $backfill = app(TaskAssignmentBackfillService::class);

        $this->assertSame(['tasks_with_pic' => 3, 'matched' => 0, 'missing' => 1, 'diverged' => 2, 'active_assignments' => 1], $backfill->report());
        $this->assertSame(1, $backfill->apply());
        $this->assertSame(0, $backfill->apply());

        $this->assertSame(['tasks_with_pic' => 3, 'matched' => 1, 'missing' => 0, 'diverged' => 2, 'active_assignments' => 2], $backfill->report());
        $this->assertEquals([$this->staff->id], $legacy->pics()->pluck('accounts.id')->all());
        $this->assertSame(0, $released->pics()->count());
        $this->assertEquals([$this->otherStaff->id], $migrated->pics()->pluck('accounts.id')->all());
    }

    public function test_backfill_command_reports_and_applies(): void
    {
        $this->legacyTask($this->staff);

        $this->artisan('tasks:backfill-assignments')
            ->expectsOutputToContain('Mode laporan')
            ->assertSuccessful();
        $this->assertSame(0, TaskAssignment::count());

        $this->artisan('tasks:backfill-assignments', ['--apply' => true])
            ->expectsOutputToContain('1 assignment dibuat.')
            ->assertSuccessful();
        $this->assertSame(1, TaskAssignment::count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, Account>  $pics
     */
    private function task(array $attributes = [], array $pics = []): Task
    {
        return Task::factory()->withPics(...$pics)->create([
            'division_id' => $this->marketing->id,
            'creator_id' => $this->manager->id,
            'status' => TaskStatus::Todo,
            'deadline' => '2026-10-30',
            ...$attributes,
        ]);
    }

    /**
     * A task as it was before task_assignments: only pic_id is set.
     */
    private function legacyTask(Account $pic): Task
    {
        $task = $this->task();
        DB::table('tasks')->where('id', $task->id)->update(['pic_id' => $pic->id]);

        return $task->fresh();
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
