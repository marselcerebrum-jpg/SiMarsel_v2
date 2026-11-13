<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAssignment;
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

    private Account $supervisor;

    private Account $financeSupervisor;

    private Account $staff;

    private Account $otherStaff;

    private Account $financeStaff;

    private Account $freelancer;

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
        $this->supervisor = $this->account(Role::SUPERVISOR, 'mkt.spv', $this->marketing);
        $this->financeSupervisor = $this->account(Role::SUPERVISOR, 'fin.spv', $this->finance);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);
        $this->financeStaff = $this->account(Role::EMPLOYEE, 'fin.staff', $this->finance);
        $this->freelancer = $this->account(Role::FREELANCE, 'freelancer', null);

        $this->task = $this->task();
    }

    // Kewenangan

    public function test_guest_cannot_assign_a_task(): void
    {
        $this->patchJson($this->url(), $this->payload())->assertUnauthorized();
    }

    public function test_staff_and_freelance_cannot_assign_even_their_own_task(): void
    {
        $freelanceTask = $this->task(['pic_id' => $this->freelancer->id]);

        $this->actingAs($this->staff)
            ->patchJson($this->url(), $this->payload([$this->staff->id, $this->otherStaff->id]))
            ->assertForbidden();
        $this->actingAs($this->staff)->getJson($this->optionsUrl())->assertForbidden();

        $this->actingAs($this->freelancer)
            ->patchJson("/api/tasks/{$freelanceTask->id}/assignment", $this->payload([$this->freelancer->id, $this->staff->id]))
            ->assertForbidden();

        $this->assertActivePics($this->task, [$this->staff]);
    }

    public function test_manager_may_assign_tasks_of_every_division(): void
    {
        $this->actingAs($this->financeManager)
            ->patchJson($this->url(), $this->payload([$this->staff->id, $this->financeStaff->id]))
            ->assertOk();

        $this->assertActivePics($this->task, [$this->financeStaff, $this->staff]);
    }

    public function test_supervisor_may_assign_only_tasks_of_their_division(): void
    {
        $this->actingAs($this->supervisor)
            ->patchJson($this->url(), $this->payload([$this->staff->id, $this->otherStaff->id]))
            ->assertOk();

        $this->actingAs($this->financeSupervisor)
            ->patchJson($this->url(), ['version' => 2, 'pic_ids' => [$this->staff->id]])
            ->assertForbidden();

        $this->assertActivePics($this->task, [$this->otherStaff, $this->staff]);
    }

    public function test_assigning_an_unknown_task_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/tasks/999999/assignment', $this->payload())
            ->assertNotFound();
    }

    // Banyak PIC

    public function test_adding_a_pic_keeps_the_existing_pic_untouched(): void
    {
        $original = $this->task->activeAssignments()->sole();

        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([$this->staff->id, $this->otherStaff->id], ['note' => 'Bantu desain.']))
            ->assertOk()
            ->assertJsonPath('message', 'PIC berhasil diperbarui.')
            ->assertJsonPath('data.pics', [
                ['id' => $this->otherStaff->id, 'fullname' => 'mkt.other'],
                ['id' => $this->staff->id, 'fullname' => 'mkt.staff'],
            ])
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.history.0.action', TaskActivity::ASSIGNED)
            ->assertJsonPath('data.history.0.changes.pics', [
                'old' => [['id' => $this->staff->id, 'name' => 'mkt.staff']],
                'new' => [
                    ['id' => $this->otherStaff->id, 'name' => 'mkt.other'],
                    ['id' => $this->staff->id, 'name' => 'mkt.staff'],
                ],
            ])
            ->assertJsonPath('data.history.0.description', 'PIC: mkt.staff → mkt.other, mkt.staff');

        $this->assertEquals($original->getAttributes(), $original->fresh()->getAttributes());
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $this->task->id, 'account_id' => $this->otherStaff->id, 'assigned_by' => $this->manager->id,
            'unassigned_at' => null, 'note' => 'Bantu desain.',
        ]);
    }

    public function test_releasing_a_pic_keeps_the_assignment_history_and_the_other_pics(): void
    {
        $task = $this->task(['pic_id' => null], [$this->staff, $this->otherStaff]);

        $this->actingAs($this->manager)
            ->patchJson("/api/tasks/{$task->id}/assignment", $this->payload([$this->otherStaff->id]))
            ->assertOk()
            ->assertJsonPath('data.pics', [['id' => $this->otherStaff->id, 'fullname' => 'mkt.other']])
            ->assertJsonPath('data.history.0.action', TaskActivity::UNASSIGNED)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.account.id', $this->staff->id)
            ->assertJsonPath('data.assignments.0.is_active', false)
            ->assertJsonPath('data.assignments.0.unassigned_by.id', $this->manager->id)
            ->assertJsonPath('data.assignments.0.unassigned_at', '2026-10-23T02:00:00Z')
            ->assertJsonPath('data.assignments.1.account.id', $this->otherStaff->id)
            ->assertJsonPath('data.assignments.1.is_active', true);

        $this->assertActivePics($task, [$this->otherStaff]);
    }

    public function test_replacing_a_pic_is_recorded_as_a_reassignment(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([$this->otherStaff->id]))
            ->assertOk()
            ->assertJsonPath('data.history.0.action', TaskActivity::REASSIGNED)
            ->assertJsonPath('data.history.0.changes.pics', [
                'old' => [['id' => $this->staff->id, 'name' => 'mkt.staff']],
                'new' => [['id' => $this->otherStaff->id, 'name' => 'mkt.other']],
            ]);

        $this->assertSame(2, $this->task->assignments()->count());
        $this->assertActivePics($this->task, [$this->otherStaff]);
    }

    public function test_a_released_pic_can_be_assigned_again_as_a_new_assignment(): void
    {
        $this->actingAs($this->manager)->patchJson($this->url(), $this->payload([$this->otherStaff->id]))->assertOk();
        $this->actingAs($this->manager)->patchJson($this->url(), ['version' => 2, 'pic_ids' => [$this->otherStaff->id, $this->staff->id]])->assertOk();

        $this->assertSame(2, TaskAssignment::where('task_id', $this->task->id)->where('account_id', $this->staff->id)->count());
        $this->assertActivePics($this->task, [$this->otherStaff, $this->staff]);
    }

    public function test_pics_may_come_from_other_divisions_and_any_pic_role(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([$this->staff->id, $this->financeStaff->id, $this->freelancer->id, $this->financeSupervisor->id]))
            ->assertOk()
            ->assertJsonCount(4, 'data.pics');

        $this->assertSame($this->marketing->id, $this->task->fresh()->division_id);
    }

    public function test_assignment_does_not_change_the_status(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress]);

        $this->actingAs($this->manager)
            ->patchJson("/api/tasks/{$task->id}/assignment", $this->payload([$this->staff->id, $this->otherStaff->id]))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_every_active_pic_sees_the_task_and_a_released_pic_no_longer_does(): void
    {
        $this->actingAs($this->manager)->patchJson($this->url(), $this->payload([$this->otherStaff->id, $this->financeStaff->id]))->assertOk();

        $this->actingAs($this->otherStaff)->getJson("/api/tasks/{$this->task->id}")->assertOk();
        $this->actingAs($this->financeStaff)->getJson("/api/tasks/{$this->task->id}")->assertOk();
        $this->actingAs($this->staff)->getJson("/api/tasks/{$this->task->id}")->assertForbidden();
    }

    public function test_a_task_past_back_log_keeps_at_least_one_pic(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids' => 'PIC wajib diisi untuk tugas yang tidak berstatus Back Log.']);

        $backlog = $this->task(['status' => TaskStatus::Backlog]);

        $this->patchJson("/api/tasks/{$backlog->id}/assignment", $this->payload([]))
            ->assertOk()
            ->assertJsonPath('data.pics', [])
            ->assertJsonPath('data.history.0.action', TaskActivity::UNASSIGNED);
    }

    // Validasi PIC

    public function test_pics_must_be_active_accounts_with_a_pic_role(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), ['version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids' => 'Daftar PIC wajib dikirim.']);

        $inactive = $this->account(Role::EMPLOYEE, 'inactive.staff', $this->marketing);
        $inactive->update(['is_active' => false]);

        foreach ([$this->manager, $this->admin, $inactive] as $pic) {
            $this->patchJson($this->url(), $this->payload([$this->staff->id, $pic->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['pic_ids.1' => 'PIC harus akun aktif dengan role Staff, Freelance, atau Supervisor.']);
        }

        $this->patchJson($this->url(), $this->payload([$this->staff->id, $this->staff->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids.0' => 'PIC yang sama dipilih lebih dari sekali.']);

        $this->assertActivePics($this->task, [$this->staff]);
    }

    public function test_sending_the_current_pics_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([$this->staff->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids' => 'Tidak ada perubahan PIC.']);

        $this->assertDatabaseCount('task_activities', 0);
    }

    public function test_version_is_required(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), ['pic_ids' => [$this->otherStaff->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version']);
    }

    // Penerusan lintas divisi

    public function test_admin_can_forward_a_task_to_another_division(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload([$this->financeStaff->id], ['division_id' => $this->finance->id]))
            ->assertOk()
            ->assertJsonPath('data.division.id', $this->finance->id)
            ->assertJsonPath('data.pics.0.id', $this->financeStaff->id)
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.history.0.action', TaskActivity::REASSIGNED)
            ->assertJsonPath('data.history.0.changes.division', [
                'old' => ['id' => $this->marketing->id, 'name' => 'Marketing'],
                'new' => ['id' => $this->finance->id, 'name' => 'Finance'],
            ]);
    }

    public function test_forwarding_drops_the_new_owner_from_the_collaborating_divisions(): void
    {
        $this->task->collaboratingDivisions()->attach($this->finance->id, ['added_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload([$this->staff->id], ['division_id' => $this->finance->id]))
            ->assertOk()
            ->assertJsonPath('data.history.0.action', TaskActivity::UPDATED);

        $this->assertSame(0, $this->task->collaboratingDivisions()->count());
    }

    public function test_only_admin_may_forward_to_another_division(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([$this->financeStaff->id], ['division_id' => $this->finance->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division_id' => 'Anda tidak dapat meneruskan tugas ke divisi di luar kewenangan Anda.']);

        $task = $this->task->fresh();
        $this->assertSame($this->marketing->id, $task->division_id);
        $this->assertSame(1, $task->version);
        $this->assertActivePics($task, [$this->staff]);
    }

    public function test_manager_may_resend_the_current_division(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([$this->otherStaff->id], ['division_id' => $this->marketing->id]))
            ->assertOk()
            ->assertJsonPath('data.pics.0.id', $this->otherStaff->id);
    }

    // Pembatasan status

    public function test_pics_cannot_be_changed_while_the_task_is_in_qa(): void
    {
        foreach ([TaskStatus::ReadyReview, TaskStatus::OnReview] as $status) {
            $task = $this->task(['status' => $status]);

            $this->actingAs($this->admin)
                ->patchJson("/api/tasks/{$task->id}/assignment", $this->payload([$this->otherStaff->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['pic_ids' => 'PIC tidak dapat diganti selama tugas dalam tahap QA.']);

            $this->assertActivePics($task, [$this->staff]);
        }
    }

    public function test_the_edit_form_does_not_change_pics(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(false), ['version' => 1, 'pic_id' => $this->otherStaff->id, 'pic_ids' => [$this->otherStaff->id]])
            ->assertOk();

        $this->assertActivePics($this->task, [$this->staff]);
    }

    public function test_pics_cannot_be_changed_on_a_done_task(): void
    {
        $task = $this->task(['status' => TaskStatus::Done, 'completed_at' => now()]);

        $this->actingAs($this->admin)
            ->patchJson("/api/tasks/{$task->id}/assignment", $this->payload([$this->otherStaff->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids' => 'Tugas yang sudah selesai tidak dapat diubah.']);

        $this->assertActivePics($task, [$this->staff]);
    }

    // Versi

    public function test_an_assignment_based_on_an_old_version_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload([$this->otherStaff->id]))
            ->assertOk();

        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([$this->staff->id, $this->financeStaff->id]))
            ->assertConflict()
            ->assertJsonPath('current_version', 2);

        $this->assertActivePics($this->task, [$this->otherStaff]);
        $this->assertDatabaseCount('task_activities', 1);
    }

    // Opsi PIC

    public function test_options_list_the_current_pics_and_every_eligible_account(): void
    {
        $response = $this->actingAs($this->manager)
            ->getJson($this->optionsUrl())
            ->assertOk()
            ->assertJsonPath('data.current_pics', [['id' => $this->staff->id, 'fullname' => 'mkt.staff']])
            ->assertJsonPath('data.current_division.id', $this->marketing->id)
            ->assertJsonPath('data.can_change_division', false)
            ->assertJsonPath('data.locked_reason', null)
            ->assertJsonPath('data.divisions', [['id' => $this->marketing->id, 'code_division' => 'MKT', 'name' => 'Marketing']]);

        $this->assertSame(
            ['fin.spv', 'fin.staff', 'freelancer', 'mkt.other', 'mkt.spv', 'mkt.staff'],
            collect($response->json('data.pics'))->pluck('fullname')->all(),
        );
        $this->assertSame(
            ['id' => $this->financeStaff->id, 'fullname' => 'fin.staff', 'role' => Role::EMPLOYEE, 'division' => ['id' => $this->finance->id, 'code_division' => 'FIN', 'name' => 'Finance']],
            collect($response->json('data.pics'))->firstWhere('id', $this->financeStaff->id),
        );
    }

    public function test_admin_options_list_every_division_to_forward_to(): void
    {
        $this->actingAs($this->admin)
            ->getJson($this->optionsUrl())
            ->assertOk()
            ->assertJsonPath('data.can_change_division', true)
            ->assertJsonCount(2, 'data.divisions');
    }

    public function test_options_and_flags_report_when_the_pics_are_locked(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview]);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$task->id}/assignment-options")
            ->assertOk()
            ->assertJsonPath('data.locked_reason', 'PIC tidak dapat diganti selama tugas dalam tahap QA.');

        $this->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_assign', false);
        $this->getJson($this->url(false))->assertJsonPath('data.can_assign', true);
        $this->actingAs($this->staff)->getJson($this->url(false))->assertJsonPath('data.can_assign', false);
    }

    /**
     * @param  array<int, int>|null  $picIds
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(?array $picIds = null, array $extra = []): array
    {
        return ['version' => 1, 'pic_ids' => $picIds ?? [$this->staff->id, $this->otherStaff->id], ...$extra];
    }

    /**
     * @param  array<int, Account>  $expected  Ordered by fullname.
     */
    private function assertActivePics(Task $task, array $expected): void
    {
        $this->assertSame(
            array_map(fn (Account $account) => $account->id, $expected),
            $task->pics()->pluck('accounts.id')->all(),
        );
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
     * @param  array<int, Account>  $pics  Extra active PICs besides pic_id.
     */
    private function task(array $attributes = [], array $pics = []): Task
    {
        return Task::factory()->withPics(...$pics)->create([
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
