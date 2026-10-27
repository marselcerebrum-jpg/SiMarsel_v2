<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskCategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskDetailTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $staff;

    private Account $otherStaff;

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
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);

        $this->task = Task::factory()->create([
            'title' => 'Desain banner promo',
            'description' => 'Banner untuk promo November.',
            'category_id' => TaskCategory::where('name', 'Konten')->value('id'),
            'division_id' => $this->marketing->id,
            'platform_id' => Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN'])->id,
            'pic_id' => $this->staff->id,
            'creator_id' => $this->manager->id,
            'priority' => 'high',
            'status' => TaskStatus::Todo,
            'start_date' => '2026-10-24',
            'deadline' => '2026-10-30',
            'requires_review' => true,
            'evidence_required' => false,
        ]);
    }

    public function test_guest_cannot_open_a_task_detail(): void
    {
        $this->getJson("/api/tasks/{$this->task->id}")->assertUnauthorized();
    }

    public function test_detail_returns_every_field_of_the_task(): void
    {
        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->task->id)
            ->assertJsonPath('data.title', 'Desain banner promo')
            ->assertJsonPath('data.description', 'Banner untuk promo November.')
            ->assertJsonPath('data.category.name', 'Konten')
            ->assertJsonPath('data.category.requires_review', true)
            ->assertJsonPath('data.division', ['id' => $this->marketing->id, 'code_division' => 'MKT', 'name' => 'Marketing'])
            ->assertJsonPath('data.pic', ['id' => $this->staff->id, 'fullname' => 'mkt.staff'])
            ->assertJsonPath('data.platform.name', 'JADIASN')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.start_date', '2026-10-24')
            ->assertJsonPath('data.deadline', '2026-10-30')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.requires_review', true)
            ->assertJsonPath('data.evidence', [])
            ->assertJsonPath('data.version', 1);
    }

    public function test_missing_information_is_returned_empty(): void
    {
        $task = Task::factory()->create([
            'division_id' => $this->marketing->id,
            'description' => null,
            'start_date' => null,
            'deadline' => null,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.platform', null)
            ->assertJsonPath('data.pic', null)
            ->assertJsonPath('data.start_date', null)
            ->assertJsonPath('data.deadline', null)
            ->assertJsonPath('data.evidence', [])
            ->assertJsonPath('data.history', []);
    }

    public function test_detail_lists_history_newest_first_with_the_actor(): void
    {
        $this->task->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);
        $this->travel(1)->minutes();
        $this->task->activities()->create([
            'account_id' => $this->admin->id,
            'action' => TaskActivity::UPDATED,
            'changes' => ['priority' => ['old' => 'medium', 'new' => 'high']],
        ]);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.0.action', 'updated')
            ->assertJsonPath('data.history.0.actor', ['id' => $this->admin->id, 'fullname' => 'admin'])
            ->assertJsonPath('data.history.0.changes.priority', ['old' => 'medium', 'new' => 'high'])
            ->assertJsonPath('data.history.1.action', 'created');
    }

    public function test_admin_and_division_manager_can_open_and_edit(): void
    {
        foreach ([$this->admin, $this->manager] as $account) {
            $this->actingAs($account)
                ->getJson("/api/tasks/{$this->task->id}")
                ->assertOk()
                ->assertJsonPath('data.can_edit', true)
                // Starting the work is the PIC's step, so neither may move a To Do task.
                ->assertJsonPath('data.next_statuses', []);
        }
    }

    public function test_only_admin_may_move_a_task_to_another_division(): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertJsonPath('data.editable_fields', Task::EDITABLE_FIELDS);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertJsonPath('data.editable_fields', array_values(array_diff(Task::EDITABLE_FIELDS, ['division_id'])));
    }

    public function test_manager_cannot_open_a_task_of_another_division(): void
    {
        $task = Task::factory()->create(['division_id' => $this->finance->id]);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$task->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki akses untuk melakukan aksi ini.');
    }

    public function test_staff_can_open_their_task_but_not_edit_it(): void
    {
        $this->actingAs($this->staff)
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.editable_fields', [])
            ->assertJsonPath('data.next_statuses', [['value' => 'in_progress', 'label' => 'On Process']]);
    }

    public function test_staff_cannot_open_a_task_assigned_to_someone_else(): void
    {
        $this->actingAs($this->otherStaff)
            ->getJson("/api/tasks/{$this->task->id}")
            ->assertForbidden();
    }

    public function test_unknown_task_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Data tidak ditemukan.');
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
