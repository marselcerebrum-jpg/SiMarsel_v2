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

class TaskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $financeManager;

    private Account $staff;

    private Account $otherStaff;

    private Account $financeStaff;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-10-23 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->financeManager = $this->account(Role::MANAGER, 'fin.manager', $this->finance);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);
        $this->financeStaff = $this->account(Role::EMPLOYEE, 'fin.staff', $this->finance);

        $this->task = $this->task();
    }

    // Kewenangan

    public function test_guest_cannot_assign_a_task(): void
    {
        $this->patchJson($this->url(), $this->payload())->assertUnauthorized();
    }

    public function test_staff_cannot_assign_or_forward_even_their_own_task(): void
    {
        $this->actingAs($this->staff)
            ->patchJson($this->url(), $this->payload(['pic_id' => $this->otherStaff->id]))
            ->assertForbidden();

        $this->actingAs($this->staff)->getJson($this->optionsUrl())->assertForbidden();

        $this->assertSame($this->staff->id, $this->task->fresh()->pic_id);
    }

    public function test_manager_cannot_assign_a_task_of_another_division(): void
    {
        $this->actingAs($this->financeManager)
            ->patchJson($this->url(), $this->payload(['pic_id' => $this->financeStaff->id]))
            ->assertForbidden();
    }

    public function test_assigning_an_unknown_task_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/tasks/999999/assignment', $this->payload())
            ->assertNotFound();
    }

    // Penetapan & penggantian PIC

    public function test_manager_can_reassign_within_their_division(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload(['pic_id' => $this->otherStaff->id]))
            ->assertOk()
            ->assertJsonPath('message', 'PIC berhasil diperbarui.')
            ->assertJsonPath('data.pic.id', $this->otherStaff->id)
            ->assertJsonPath('data.pic.fullname', 'mkt.other')
            ->assertJsonPath('data.version', 2);

        $this->assertSame($this->otherStaff->id, $this->task->fresh()->pic_id);
    }

    public function test_assigning_a_pic_to_an_unassigned_task(): void
    {
        $task = $this->task(['pic_id' => null, 'status' => TaskStatus::Backlog]);

        $this->actingAs($this->manager)
            ->patchJson("/api/tasks/{$task->id}/assignment", $this->payload(['pic_id' => $this->staff->id]))
            ->assertOk()
            ->assertJsonPath('data.pic.id', $this->staff->id)
            ->assertJsonPath('data.history.0.action', TaskActivity::ASSIGNED)
            ->assertJsonPath('data.history.0.changes.pic', [
                'old' => null,
                'new' => ['id' => $this->staff->id, 'name' => 'mkt.staff'],
            ]);
    }

    public function test_reassignment_does_not_change_the_status(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress]);

        $this->actingAs($this->manager)
            ->patchJson("/api/tasks/{$task->id}/assignment", $this->payload(['pic_id' => $this->otherStaff->id]))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_new_pic_sees_the_task_and_the_previous_pic_no_longer_does(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload(['pic_id' => $this->otherStaff->id]))
            ->assertOk();

        $this->actingAs($this->otherStaff)->getJson("/api/tasks/{$this->task->id}")->assertOk();
        $this->actingAs($this->staff)->getJson("/api/tasks/{$this->task->id}")->assertForbidden();

        $this->actingAs($this->admin)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.pic.id', $this->otherStaff->id);
    }

    // Validasi PIC

    public function test_pic_is_required_and_must_be_staff_of_the_task_division(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload(['pic_id' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id' => 'PIC wajib dipilih.']);

        foreach ([$this->financeStaff, $this->manager, $this->admin] as $pic) {
            $this->patchJson($this->url(), $this->payload(['pic_id' => $pic->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['pic_id' => 'PIC harus Staff dari divisi yang dipilih.']);
        }

        $this->assertSame($this->staff->id, $this->task->fresh()->pic_id);
    }

    public function test_choosing_the_current_pic_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload(['pic_id' => $this->staff->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id' => 'PIC yang dipilih sudah menjadi PIC tugas ini.']);

        $this->assertDatabaseCount('task_activities', 0);
    }

    public function test_version_is_required(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), ['pic_id' => $this->otherStaff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version']);
    }

    // Penerusan lintas divisi

    public function test_admin_can_forward_a_task_to_another_division(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload([
                'division_id' => $this->finance->id,
                'pic_id' => $this->financeStaff->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.division.id', $this->finance->id)
            ->assertJsonPath('data.pic.id', $this->financeStaff->id)
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.history.0.action', TaskActivity::REASSIGNED)
            ->assertJsonPath('data.history.0.changes.division', [
                'old' => ['id' => $this->marketing->id, 'name' => 'Marketing'],
                'new' => ['id' => $this->finance->id, 'name' => 'Finance'],
            ]);
    }

    public function test_forwarding_requires_a_pic_from_the_target_division(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload([
                'division_id' => $this->finance->id,
                'pic_id' => $this->otherStaff->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id' => 'PIC harus Staff dari divisi yang dipilih.']);
    }

    public function test_manager_cannot_forward_outside_their_division(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([
                'division_id' => $this->finance->id,
                'pic_id' => $this->financeStaff->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division_id' => 'Anda tidak dapat meneruskan tugas ke divisi di luar kewenangan Anda.']);

        $task = $this->task->fresh();
        $this->assertSame($this->marketing->id, $task->division_id);
        $this->assertSame($this->staff->id, $task->pic_id);
        $this->assertSame(1, $task->version);
    }

    public function test_manager_may_resend_their_own_division(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([
                'division_id' => $this->marketing->id,
                'pic_id' => $this->otherStaff->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.pic.id', $this->otherStaff->id);
    }

    // Pembatasan status

    public function test_pic_cannot_be_changed_while_the_task_is_in_qa(): void
    {
        foreach ([TaskStatus::ReadyForQa, TaskStatus::OnQa] as $status) {
            $task = $this->task(['status' => $status]);

            $this->actingAs($this->admin)
                ->patchJson("/api/tasks/{$task->id}/assignment", $this->payload(['pic_id' => $this->otherStaff->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['pic_id' => 'PIC tidak dapat diganti selama tugas dalam tahap QA.']);

            $this->assertSame($this->staff->id, $task->fresh()->pic_id);
        }
    }

    public function test_pic_cannot_be_changed_through_the_edit_form_while_in_qa(): void
    {
        $task = $this->task(['status' => TaskStatus::OnQa]);

        $this->actingAs($this->admin)
            ->patchJson("/api/tasks/{$task->id}", $this->payload(['pic_id' => $this->otherStaff->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id' => 'PIC tidak dapat diganti selama tugas dalam tahap QA.']);
    }

    public function test_pic_cannot_be_changed_on_a_done_task(): void
    {
        $task = $this->task(['status' => TaskStatus::Done, 'completed_at' => now()]);

        $this->actingAs($this->admin)
            ->patchJson("/api/tasks/{$task->id}/assignment", $this->payload(['pic_id' => $this->otherStaff->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id']);

        $this->assertSame($this->staff->id, $task->fresh()->pic_id);
    }

    // Histori

    public function test_each_reassignment_is_kept_in_the_history(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload(['pic_id' => $this->otherStaff->id]))
            ->assertOk();

        $this->actingAs($this->manager)
            ->patchJson($this->url(), ['version' => 2, 'pic_id' => $this->staff->id])
            ->assertOk()
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.0.action', TaskActivity::REASSIGNED)
            ->assertJsonPath('data.history.0.actor.id', $this->manager->id)
            ->assertJsonPath('data.history.0.changes.pic', [
                'old' => ['id' => $this->otherStaff->id, 'name' => 'mkt.other'],
                'new' => ['id' => $this->staff->id, 'name' => 'mkt.staff'],
            ])
            ->assertJsonPath('data.history.1.changes.pic', [
                'old' => ['id' => $this->staff->id, 'name' => 'mkt.staff'],
                'new' => ['id' => $this->otherStaff->id, 'name' => 'mkt.other'],
            ]);
    }

    public function test_an_assignment_based_on_an_old_version_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['pic_id' => $this->otherStaff->id]))
            ->assertOk();

        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload(['pic_id' => $this->staff->id]))
            ->assertConflict()
            ->assertJsonPath('current_version', 2);

        $this->assertSame($this->otherStaff->id, $this->task->fresh()->pic_id);
        $this->assertDatabaseCount('task_activities', 1);
    }

    // Opsi PIC

    public function test_manager_options_list_only_staff_of_their_division(): void
    {
        $this->actingAs($this->manager)
            ->getJson($this->optionsUrl())
            ->assertOk()
            ->assertJsonPath('data.current_pic', ['id' => $this->staff->id, 'fullname' => 'mkt.staff'])
            ->assertJsonPath('data.current_division.id', $this->marketing->id)
            ->assertJsonPath('data.can_change_division', false)
            ->assertJsonPath('data.locked_reason', null)
            ->assertJsonCount(1, 'data.divisions')
            ->assertJsonPath('data.divisions.0.id', $this->marketing->id)
            ->assertJsonPath('data.divisions.0.pics', [
                ['id' => $this->otherStaff->id, 'fullname' => 'mkt.other'],
                ['id' => $this->staff->id, 'fullname' => 'mkt.staff'],
            ]);
    }

    public function test_admin_options_list_staff_of_every_division(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson($this->optionsUrl())
            ->assertOk()
            ->assertJsonPath('data.can_change_division', true);

        $divisions = collect($response->json('data.divisions'))->keyBy('id');

        $this->assertSame([['id' => $this->financeStaff->id, 'fullname' => 'fin.staff']], $divisions[$this->finance->id]['pics']);
        $this->assertCount(2, $divisions[$this->marketing->id]['pics']);
    }

    public function test_options_and_flags_report_when_the_pic_is_locked(): void
    {
        $task = $this->task(['status' => TaskStatus::OnQa]);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$task->id}/assignment-options")
            ->assertOk()
            ->assertJsonPath('data.locked_reason', 'PIC tidak dapat diganti selama tugas dalam tahap QA.');

        $this->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_assign', false);
        $this->getJson($this->url(false))->assertJsonPath('data.can_assign', true);
        $this->actingAs($this->staff)->getJson($this->url(false))->assertJsonPath('data.can_assign', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['version' => 1, 'pic_id' => $this->otherStaff->id, ...$overrides];
    }

    private function url(bool $assignment = true): string
    {
        return "/api/tasks/{$this->task->id}".($assignment ? '/assignment' : '');
    }

    private function optionsUrl(): string
    {
        return "/api/tasks/{$this->task->id}/assignment-options";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): Task
    {
        return Task::factory()->create([
            'title' => 'Desain banner promo',
            'division_id' => $this->marketing->id,
            'pic_id' => $this->staff->id,
            'creator_id' => $this->manager->id,
            'status' => TaskStatus::Todo,
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
