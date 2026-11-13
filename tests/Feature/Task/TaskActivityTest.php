<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Exceptions\TaskActivityImmutableException;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskActivityTest extends TestCase
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
        $this->travelTo('2026-11-06 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->financeManager = $this->account(Role::MANAGER, 'fin.manager', $this->finance);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);
    }

    // Akses

    public function test_guest_cannot_read_task_history(): void
    {
        $this->getJson($this->url($this->task()))->assertUnauthorized();
    }

    public function test_admin_reads_the_history_of_any_task(): void
    {
        $task = $this->task(['division_id' => $this->finance->id, 'pic_id' => null]);

        $this->actingAs($this->admin)->getJson($this->url($task))->assertOk();
    }

    public function test_manager_reads_only_the_history_of_their_division(): void
    {
        $task = $this->task();

        $this->actingAs($this->manager)->getJson($this->url($task))->assertOk();
        $this->actingAs($this->financeManager)->getJson($this->url($task))->assertForbidden();
    }

    public function test_staff_reads_only_the_history_of_tasks_assigned_to_them(): void
    {
        $task = $this->task();

        $this->actingAs($this->staff)->getJson($this->url($task))->assertOk();
        $this->actingAs($this->otherStaff)->getJson($this->url($task))->assertForbidden();
    }

    public function test_unknown_task_returns_not_found(): void
    {
        $this->actingAs($this->admin)->getJson('/api/tasks/999999/activities')->assertNotFound();
    }

    // Tampilan

    public function test_task_without_activity_returns_an_empty_history(): void
    {
        $this->actingAs($this->manager)
            ->getJson($this->url($this->task()))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_each_entry_shows_type_actor_time_and_description(): void
    {
        $task = $this->task();
        $task->activities()->create([
            'account_id' => $this->manager->id,
            'action' => TaskActivity::STATUS_CHANGED,
            'changes' => ['status' => ['old' => 'todo', 'new' => 'in_progress']],
        ]);

        $this->actingAs($this->staff)
            ->getJson($this->url($task))
            ->assertOk()
            ->assertJsonPath('data.0.action', TaskActivity::STATUS_CHANGED)
            ->assertJsonPath('data.0.action_label', 'Status diubah')
            ->assertJsonPath('data.0.actor', ['id' => $this->manager->id, 'fullname' => 'mkt.manager'])
            ->assertJsonPath('data.0.created_at', '2026-11-06T02:00:00Z')
            ->assertJsonPath('data.0.description', 'Status: To Do → On Process')
            ->assertJsonPath('data.0.changes.status', ['old' => 'todo', 'new' => 'in_progress']);
    }

    public function test_history_is_newest_first_and_stable_within_the_same_second(): void
    {
        $task = $this->task();
        $first = $task->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);
        $second = $task->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::ASSIGNED]);
        $this->travel(5)->minutes();
        $latest = $task->activities()->create(['account_id' => $this->staff->id, 'action' => TaskActivity::STATUS_CHANGED]);

        $this->actingAs($this->manager)
            ->getJson($this->url($task))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$latest->id, $second->id, $first->id]);
    }

    public function test_actor_name_is_still_shown_after_the_actor_account_is_deleted(): void
    {
        $task = $this->task(['pic_id' => $this->otherStaff->id]);
        $this->send('patchJson', "/api/tasks/{$task->id}/assignment", $this->manager, $task, ['pic_id' => $this->staff->id]);

        $this->actingAs($this->financeManager)->deleteJson("/api/accounts/{$this->manager->id}")->assertSuccessful();
        $this->assertModelMissing($this->manager);

        $this->actingAs($this->admin)
            ->getJson($this->url($task))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', TaskActivity::REASSIGNED)
            ->assertJsonPath('data.0.actor', ['id' => null, 'fullname' => 'mkt.manager'])
            ->assertJsonPath('data.0.description', 'PIC: mkt.other → mkt.staff');
    }

    public function test_actor_name_is_copied_when_the_entry_is_written(): void
    {
        $activity = $this->task()->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);

        $this->assertSame('mkt.manager', $activity->fresh()->actor_name_snapshot);
    }

    public function test_entry_shows_the_current_account_name_while_the_account_exists(): void
    {
        $task = $this->task();
        $task->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);
        $this->manager->update(['fullname' => 'Manajer Marketing']);

        $this->actingAs($this->admin)
            ->getJson($this->url($task))
            ->assertJsonPath('data.0.actor', ['id' => $this->manager->id, 'fullname' => 'Manajer Marketing']);
    }

    public function test_migration_fills_the_actor_name_of_older_entries_whose_account_still_exists(): void
    {
        $task = $this->task();
        $leaver = $this->account(Role::EMPLOYEE, 'leaver', $this->marketing);
        $kept = $task->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);
        $orphan = $task->activities()->create(['account_id' => $leaver->id, 'action' => TaskActivity::UPDATED]);
        $leaver->delete();

        $migration = require database_path('migrations/2026_11_09_104845_add_actor_name_snapshot_to_task_activities_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame('mkt.manager', $kept->fresh()->actor_name_snapshot);
        $this->assertNull($orphan->fresh()->actor_name_snapshot);
        $this->assertNull($orphan->fresh()->account_id);
    }

    // Pencatatan

    public function test_the_whole_workflow_is_recorded_and_earlier_entries_stay_after_status_and_pic_change(): void
    {
        $task = Task::findOrFail($this->actingAs($this->manager)
            ->postJson('/api/tasks', ['title' => 'Desain banner', 'division_id' => $this->marketing->id, 'pic_id' => $this->otherStaff->id])
            ->assertCreated()
            ->json('data.id'));

        $this->send('putJson', "/api/tasks/{$task->id}", $this->manager, $task, ['title' => 'Desain banner promo', 'priority' => 'high', 'requires_review' => true]);
        $this->send('patchJson', "/api/tasks/{$task->id}/assignment", $this->manager, $task, ['pic_id' => $this->staff->id]);
        $this->moveTo($task, $this->manager, 'todo');
        $this->moveTo($task, $this->staff, 'in_progress');
        $this->actingAs($this->staff)
            ->postJson("/api/tasks/{$task->id}/evidences", ['url' => 'https://drive.example.com/banner'])
            ->assertCreated();
        $this->moveTo($task, $this->staff, 'ready_review');
        $this->moveTo($task, $this->manager, 'on_review');
        $this->send('postJson', "/api/tasks/{$task->id}/reviews", $this->manager, $task, ['decision' => 'revision', 'note' => 'Perbaiki warna.']);
        $this->moveTo($task, $this->staff, 'ready_review');
        $this->moveTo($task, $this->manager, 'on_review');
        $this->send('postJson', "/api/tasks/{$task->id}/reviews", $this->manager, $task, ['decision' => 'approve']);

        $response = $this->actingAs($this->staff)->getJson($this->url($task))->assertOk();

        $this->assertSame([
            TaskActivity::APPROVED,
            TaskActivity::STATUS_CHANGED,
            TaskActivity::RESUBMITTED,
            TaskActivity::REVISION_REQUESTED,
            TaskActivity::STATUS_CHANGED,
            TaskActivity::STATUS_CHANGED,
            TaskActivity::EVIDENCE_SUBMITTED,
            TaskActivity::STATUS_CHANGED,
            TaskActivity::STATUS_CHANGED,
            TaskActivity::REASSIGNED,
            TaskActivity::UPDATED,
            TaskActivity::CREATED,
        ], $response->json('data.*.action'));

        $history = collect($response->json('data'))->keyBy('action');

        $this->assertSame('Membuat tugas.', $history[TaskActivity::CREATED]['description']);
        $this->assertSame(['old' => 'medium', 'new' => 'high'], $history[TaskActivity::UPDATED]['changes']['priority']);
        $this->assertStringContainsString('Prioritas: Medium → High', $history[TaskActivity::UPDATED]['description']);
        $this->assertSame('PIC: mkt.other → mkt.staff', $history[TaskActivity::REASSIGNED]['description']);
        $this->assertSame('Evidence: https://drive.example.com/banner', $history[TaskActivity::EVIDENCE_SUBMITTED]['description']);
        $this->assertSame('Status: On Review → On Process; Catatan: Perbaiki warna.', $history[TaskActivity::REVISION_REQUESTED]['description']);
        $this->assertSame('Status: On Process → Ready Review', $history[TaskActivity::RESUBMITTED]['description']);
        $this->assertSame('Status: On Review → Done', $history[TaskActivity::APPROVED]['description']);
        $this->assertSame($this->manager->id, $history[TaskActivity::APPROVED]['actor']['id']);
    }

    public function test_resubmission_through_the_edit_form_is_recorded_as_resubmitted(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview, 'requires_review' => true]);
        $this->send('postJson', "/api/tasks/{$task->id}/reviews", $this->manager, $task, ['decision' => 'revision', 'note' => 'Ulangi.']);

        $this->send('putJson', "/api/tasks/{$task->id}", $this->staff, $task, ['status' => 'ready_review']);

        $this->assertSame(TaskActivity::RESUBMITTED, $task->activities()->value('action'));
    }

    public function test_rejected_action_leaves_no_history(): void
    {
        $task = $this->task();

        $this->actingAs($this->staff)
            ->patchJson("/api/tasks/{$task->id}/status", ['version' => $task->version, 'status' => 'done'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('task_activities', 0);
    }

    // Integritas

    public function test_history_cannot_be_changed_or_removed_through_the_api(): void
    {
        $task = $this->task();
        $activity = $task->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);

        $this->actingAs($this->admin)->putJson($this->url($task), ['action' => 'updated'])->assertMethodNotAllowed();
        $this->actingAs($this->admin)->deleteJson($this->url($task))->assertMethodNotAllowed();
        $this->actingAs($this->admin)->patchJson("{$this->url($task)}/{$activity->id}", [])->assertNotFound();

        $this->assertDatabaseHas('task_activities', ['id' => $activity->id, 'action' => TaskActivity::CREATED]);
    }

    public function test_history_entry_cannot_be_updated(): void
    {
        $activity = $this->task()->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);

        $this->expectException(TaskActivityImmutableException::class);

        $activity->update(['action' => TaskActivity::APPROVED]);
    }

    public function test_history_entry_cannot_be_deleted(): void
    {
        $activity = $this->task()->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);

        try {
            $activity->delete();
            $this->fail('Deleting a history entry should be refused.');
        } catch (TaskActivityImmutableException) {
            $this->assertDatabaseHas('task_activities', ['id' => $activity->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $url, Account $actor, Task $task, array $data): void
    {
        $this->actingAs($actor)->{$method}($url, ['version' => $task->fresh()->version, ...$data])->assertOk();
    }

    private function moveTo(Task $task, Account $actor, string $status): void
    {
        $this->send('patchJson', "/api/tasks/{$task->id}/status", $actor, $task, ['status' => $status]);
    }

    private function url(Task $task): string
    {
        return "/api/tasks/{$task->id}/activities";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): Task
    {
        return Task::factory()->create([
            'division_id' => $this->marketing->id,
            'pic_id' => $this->staff->id,
            'creator_id' => $this->manager->id,
            'status' => TaskStatus::Todo,
            'requires_review' => false,
            'evidence_required' => false,
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
