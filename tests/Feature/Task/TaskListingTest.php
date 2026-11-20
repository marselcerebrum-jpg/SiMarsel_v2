<?php

namespace Tests\Feature\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskListingTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $staff;

    private Account $otherStaff;

    private Task $staffTask;

    private Task $otherStaffTask;

    private Task $financeTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);

        $this->staffTask = $this->task('Update landing page', $this->marketing, $this->staff, [
            'priority' => TaskPriority::High,
            'status' => TaskStatus::InProgress,
            'deadline' => '2026-10-20',
        ]);
        $this->otherStaffTask = $this->task('Design banner promo', $this->marketing, $this->otherStaff, [
            'priority' => TaskPriority::Low,
            'status' => TaskStatus::Todo,
            'deadline' => null,
        ]);
        $this->financeTask = $this->task('Monthly report', $this->finance, null, [
            'priority' => TaskPriority::Urgent,
            'status' => TaskStatus::Backlog,
            'deadline' => '2026-10-25',
        ]);
    }

    // Akses

    public function test_guest_cannot_access_task_endpoints(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->getJson('/api/tasks/meta')->assertUnauthorized();
        $this->getJson("/api/tasks/{$this->staffTask->id}")->assertUnauthorized();
    }

    public function test_admin_sees_every_task_across_divisions(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_narrow_to_one_division(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks?division=FIN')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->financeTask->id);
    }

    public function test_every_role_sees_every_task_across_divisions(): void
    {
        foreach ([$this->manager, $this->staff, $this->otherStaff] as $account) {
            $response = $this->actingAs($account)->getJson('/api/tasks')->assertOk();

            $this->assertEqualsCanonicalizing(
                [$this->staffTask->id, $this->otherStaffTask->id, $this->financeTask->id],
                array_column($response->json('data'), 'id'),
            );
        }
    }

    public function test_division_filter_narrows_the_global_list(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/tasks?division=FIN&search=report&status=backlog')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->financeTask->id);
    }

    public function test_manager_without_a_division_still_manages_every_division(): void
    {
        $manager = Account::where('username', 'manager')->first();

        $this->actingAs($manager)
            ->getJson('/api/tasks/meta')
            ->assertJsonPath('data.can_create', true);

        $this->actingAs($manager)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.can_edit', true);
    }

    public function test_my_tasks_only_lists_tasks_the_account_is_an_active_pic_of(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/tasks?scope=my_tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->staffTask->id);
    }

    public function test_every_role_opens_any_task_by_id_but_tasks_of_others_stay_read_only(): void
    {
        $this->actingAs($this->staff);

        $this->getJson("/api/tasks/{$this->otherStaffTask->id}")->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_assign', false)
            ->assertJsonPath('data.editable_fields', []);
        $this->getJson("/api/tasks/{$this->financeTask->id}")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->getJson("/api/tasks/{$this->staffTask->id}")->assertOk()->assertJsonPath('data.can_edit', true);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$this->financeTask->id}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_an_inactive_account_sees_no_task(): void
    {
        $this->staff->update(['is_active' => false]);

        $this->actingAs($this->staff)->getJson('/api/tasks')->assertForbidden();
        $this->actingAs($this->staff)->getJson("/api/tasks/{$this->staffTask->id}")->assertForbidden();
    }

    public function test_unknown_task_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/999999')
            ->assertNotFound();
    }

    public function test_pic_and_managers_can_edit_tasks_from_the_list(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/tasks?scope=my_tasks')
            ->assertJsonPath('data.0.can_view_detail', true)
            ->assertJsonPath('data.0.can_edit', true);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$this->staffTask->id}")
            ->assertJsonPath('data.can_edit', true);

        $this->actingAs($this->admin)
            ->getJson("/api/tasks/{$this->financeTask->id}")
            ->assertJsonPath('data.can_edit', true);
    }

    // Bentuk data

    public function test_list_returns_task_fields_for_board_and_table(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/tasks?scope=my_tasks')
            ->assertOk()
            ->assertJsonPath('data.0', [
                'id' => $this->staffTask->id,
                'title' => 'Update landing page',
                'division' => ['code_division' => 'MKT', 'name' => 'Marketing'],
                'pics' => [['id' => $this->staff->id, 'fullname' => $this->staff->fullname]],
                'priority' => 'high',
                'status' => 'in_progress',
                'start_date' => null,
                'deadline' => '2026-10-20',
                'requires_review' => true,
                'evidence_required' => false,
                'evidence_count' => 0,
                'can_view_detail' => true,
                'can_edit' => true,
                'can_change_status' => true,
                'can_assign' => false,
                'can_delete' => false,
                'version' => 1,
                'updated_at' => $this->staffTask->updated_at->toIso8601ZuluString(),
            ]);
    }

    public function test_detail_includes_description_and_creator(): void
    {
        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$this->staffTask->id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Task description')
            ->assertJsonPath('data.creator', ['id' => $this->manager->id, 'fullname' => $this->manager->fullname])
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_task_without_pic_returns_null_pic(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks?division=FIN')
            ->assertJsonPath('data.0.pic', null);
    }

    // Filter & pencarian

    public function test_search_matches_task_title(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks?search=banner')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->otherStaffTask->id);
    }

    public function test_filters_by_priority_and_status(): void
    {
        $this->actingAs($this->admin);

        $this->getJson('/api/tasks?priority=urgent')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->financeTask->id);

        $this->getJson('/api/tasks?status=todo')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->otherStaffTask->id);
    }

    public function test_status_counts_follow_scope_and_filters(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks')
            ->assertJsonPath('meta.status_counts', [
                'backlog' => 1,
                'todo' => 1,
                'in_progress' => 1,
                'ready_review' => 0,
                'on_review' => 0,
                'done' => 0,
            ]);

        $this->actingAs($this->manager)
            ->getJson('/api/tasks?priority=high')
            ->assertJsonPath('meta.status_counts.in_progress', 1)
            ->assertJsonPath('meta.status_counts.todo', 0)
            ->assertJsonPath('meta.status_counts.backlog', 0);
    }

    public function test_rejects_invalid_filter_values(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks?division=XXX&priority=critical&status=archived&sort=password&direction=up&per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division', 'priority', 'status', 'sort', 'direction', 'per_page']);
    }

    // Urutan & pagination

    public function test_sorts_by_priority_severity(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame(
            [$this->financeTask->id, $this->staffTask->id, $this->otherStaffTask->id],
            array_column($this->getJson('/api/tasks?sort=priority&direction=desc')->json('data'), 'id'),
        );
    }

    public function test_sorts_by_status_in_workflow_order(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame(
            [$this->financeTask->id, $this->otherStaffTask->id, $this->staffTask->id],
            array_column($this->getJson('/api/tasks?sort=status')->json('data'), 'id'),
        );
    }

    public function test_tasks_without_deadline_come_last(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame(
            [$this->staffTask->id, $this->financeTask->id, $this->otherStaffTask->id],
            array_column($this->getJson('/api/tasks?sort=deadline')->json('data'), 'id'),
        );
        $this->assertSame(
            [$this->financeTask->id, $this->staffTask->id, $this->otherStaffTask->id],
            array_column($this->getJson('/api/tasks?sort=deadline&direction=desc')->json('data'), 'id'),
        );
    }

    public function test_sorts_by_division_and_pic_name(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame(
            $this->financeTask->id,
            $this->getJson('/api/tasks?sort=division')->json('data.0.id'),
        );
        $this->assertSame(
            $this->financeTask->id,
            $this->getJson('/api/tasks?sort=pic')->json('data.0.id'),
            'Tasks without PIC sort first ascending.',
        );
    }

    public function test_paginates_when_per_page_is_given(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks?per_page=2&page=2&sort=title')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->staffTask->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.status_counts.backlog', 1);
    }

    // Meta

    public function test_meta_for_admin_defaults_to_all_divisions(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/meta')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.can_create', true)
            ->assertJsonPath('data.default_division', null)
            ->assertJsonPath('data.divisions', [
                ['code_division' => 'FIN', 'name' => 'Finance'],
                ['code_division' => 'MKT', 'name' => 'Marketing'],
            ])
            ->assertJsonCount(6, 'data.statuses')
            ->assertJsonPath('data.statuses.2', ['value' => 'in_progress', 'label' => 'On Process'])
            ->assertJsonCount(4, 'data.priorities')
            ->assertJsonPath('data.role_code', 'ADM')
            ->assertJsonCount(7, 'data.permissions');
    }

    public function test_meta_reports_the_role_code_and_permissions_of_every_role(): void
    {
        $expected = [
            Role::SUPERVISOR => ['SPV', ['task.assign', 'task.change_status', 'task.create', 'task.delete', 'task.review', 'task.update', 'task.view_all']],
            Role::FREELANCE => ['FRL', ['task.change_status', 'task.update', 'task.view_all']],
            Role::EMPLOYEE => ['EMP', ['task.change_status', 'task.update', 'task.view_all']],
        ];

        foreach ($expected as $code => [$roleCode, $permissions]) {
            $account = Account::create([
                'role_id' => Role::where('code_role', $code)->value('id'),
                'division_id' => null,
                'fullname' => "Akun {$roleCode}",
                'username' => 'akun.'.strtolower($roleCode),
                'password' => 'Password123!',
            ]);

            $this->actingAs($account)
                ->getJson('/api/tasks/meta')
                ->assertOk()
                ->assertJsonPath('data.role', 'staff')
                ->assertJsonPath('data.role_code', $roleCode)
                ->assertJsonPath('data.permissions', $permissions);

            $this->assertTrue($account->hasPermission('task.view_all'));
            $this->assertSame($code === Role::SUPERVISOR, $account->hasPermission('task.assign'));
        }
    }

    public function test_meta_for_manager_lists_every_division_and_starts_on_their_own(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/tasks/meta')
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.can_create', true)
            ->assertJsonPath('data.division', 'MKT')
            ->assertJsonPath('data.default_division', 'MKT')
            ->assertJsonPath('data.divisions', [
                ['code_division' => 'FIN', 'name' => 'Finance'],
                ['code_division' => 'MKT', 'name' => 'Marketing'],
            ]);
    }

    public function test_meta_for_staff_lists_every_division(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/tasks/meta')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.can_create', false)
            ->assertJsonPath('data.default_division', null)
            ->assertJsonPath('data.divisions', [
                ['code_division' => 'FIN', 'name' => 'Finance'],
                ['code_division' => 'MKT', 'name' => 'Marketing'],
            ]);
    }

    private function account(string $role, string $username, ?Division $division): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $division?->id,
            'fullname' => ucwords(str_replace('.', ' ', $username)),
            'username' => $username,
            'password' => 'Password123!',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(string $title, Division $division, ?Account $pic, array $attributes = []): Task
    {
        return Task::factory()->create([
            'title' => $title,
            'description' => 'Task description',
            'division_id' => $division->id,
            'pic_id' => $pic?->id,
            'creator_id' => $this->manager->id,
            'start_date' => null,
            'requires_review' => true,
            'evidence_required' => false,
            ...$attributes,
        ]);
    }
}
