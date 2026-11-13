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

class TaskMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private const YESTERDAY = '2026-11-05';

    private const TODAY = '2026-11-06';

    private const TOMORROW = '2026-11-07';

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $staff;

    private Task $financeTodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::TODAY.' 10:00:00');
        $this->seed(DatabaseSeeder::class);

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin.user', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);

        // Marketing
        $this->task($this->marketing, $this->staff, TaskStatus::Backlog, TaskPriority::Urgent, self::YESTERDAY, 'Overdue urgent backlog');
        $this->task($this->marketing, $otherStaff, TaskStatus::Todo, TaskPriority::Low, self::TODAY, 'Due today');
        $this->task($this->marketing, $this->staff, TaskStatus::InProgress, TaskPriority::High, null, 'No deadline');
        $this->task($this->marketing, $otherStaff, TaskStatus::ReadyReview, TaskPriority::Medium, self::TOMORROW, 'Upcoming');
        $this->task($this->marketing, $otherStaff, TaskStatus::OnReview, TaskPriority::Urgent, self::YESTERDAY, 'Overdue urgent QA');
        $this->task($this->marketing, $this->staff, TaskStatus::Done, TaskPriority::Urgent, self::YESTERDAY, 'Done late');
        // Finance
        $this->task($this->finance, null, TaskStatus::Done, TaskPriority::Low, self::TODAY, 'Done today');
        $this->financeTodo = $this->task($this->finance, null, TaskStatus::Todo, TaskPriority::Medium, '2026-10-01', 'Long overdue');
    }

    public function test_guest_cannot_read_monitoring(): void
    {
        $this->getJson('/api/tasks/monitoring')->assertUnauthorized();
    }

    public function test_report_aggregates_every_metric(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring')
            ->assertOk()
            ->assertJsonPath('data.scope', ['key' => 'report', 'label' => 'Seluruh divisi'])
            ->assertJsonPath('data.as_of', self::TODAY)
            ->assertJsonPath('data.metrics', [
                'total_tasks' => 8,
                'backlog' => 1,
                'todo' => 2,
                'on_process' => 1,
                'ready_review' => 1,
                'on_review' => 1,
                'completed' => 2,
                'unfinished' => 6,
                'overdue' => 3,
                'due_today' => 1,
                'urgent' => 2,
                'completion_rate' => 25,
            ]);
    }

    public function test_manager_and_staff_see_the_cross_division_report(): void
    {
        foreach ([$this->manager, $this->staff] as $account) {
            $this->actingAs($account)
                ->getJson('/api/tasks/monitoring')
                ->assertOk()
                ->assertJsonPath('data.metrics.total_tasks', 8)
                ->assertJsonPath('data.scope.label', 'Seluruh divisi');
        }
    }

    public function test_report_does_not_grant_access_to_tasks_outside_authority(): void
    {
        $this->actingAs($this->staff)->getJson('/api/tasks/monitoring')->assertOk();

        $this->actingAs($this->staff)->getJson("/api/tasks/{$this->financeTodo->id}")->assertForbidden();
        $this->actingAs($this->manager)
            ->patchJson("/api/tasks/{$this->financeTodo->id}/status", ['status' => 'in_progress', 'version' => 1])
            ->assertForbidden();
    }

    public function test_tasklist_scope_follows_tasklist_visibility_with_clear_label(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/tasks/monitoring?scope=tasklist')
            ->assertOk()
            ->assertJsonPath('data.scope', ['key' => 'tasklist', 'label' => 'Tugas yang ditugaskan kepada Anda'])
            ->assertJsonPath('data.metrics.total_tasks', 3)
            ->assertJsonPath('data.metrics.completed', 1)
            ->assertJsonPath('data.metrics.overdue', 1)
            ->assertJsonPath('data.metrics.completion_rate', 33.33);

        $this->actingAs($this->manager)
            ->getJson('/api/tasks/monitoring?scope=tasklist')
            ->assertOk()
            ->assertJsonPath('data.scope.label', 'Tugas divisi Anda')
            ->assertJsonPath('data.metrics.total_tasks', 6);
    }

    public function test_tasklist_scope_matches_the_tasklist_counts(): void
    {
        $tasklist = $this->actingAs($this->manager)->getJson('/api/tasks?priority=urgent')->assertOk();
        $monitoring = $this->actingAs($this->manager)->getJson('/api/tasks/monitoring?scope=tasklist&priority=urgent')->assertOk();

        $this->assertSame(count($tasklist->json('data')), $monitoring->json('data.metrics.total_tasks'));
        $this->assertSame($tasklist->json('meta.status_counts.done'), $monitoring->json('data.metrics.completed'));
        $this->assertSame($tasklist->json('meta.status_counts.backlog'), $monitoring->json('data.metrics.backlog'));
    }

    public function test_tasklist_scope_cannot_be_widened_by_division_filter(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/tasks/monitoring?scope=tasklist&division=FIN')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 0);
    }

    public function test_division_filter_narrows_every_metric(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?division=FIN')
            ->assertOk()
            ->assertJsonPath('data.filters.division', 'FIN')
            ->assertJsonPath('data.metrics.total_tasks', 2)
            ->assertJsonPath('data.metrics.completed', 1)
            ->assertJsonPath('data.metrics.overdue', 1)
            ->assertJsonPath('data.metrics.due_today', 0)
            ->assertJsonPath('data.metrics.completion_rate', 50)
            ->assertJsonCount(1, 'data.by_division');
    }

    public function test_status_and_priority_filters_apply(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?priority=urgent')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 3)
            ->assertJsonPath('data.metrics.urgent', 2)
            ->assertJsonPath('data.metrics.completed', 1);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?status=todo')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 2)
            ->assertJsonPath('data.metrics.todo', 2)
            ->assertJsonPath('data.metrics.backlog', 0)
            ->assertJsonPath('data.metrics.completion_rate', 0);
    }

    public function test_period_filter_uses_deadline_and_excludes_tasks_without_one(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?date_from='.self::YESTERDAY.'&date_to='.self::TODAY)
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 5)
            ->assertJsonPath('data.metrics.overdue', 2)
            ->assertJsonPath('data.metrics.due_today', 1);
    }

    public function test_period_defaults_to_deadline_basis_and_reports_it(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring')
            ->assertOk()
            ->assertJsonPath('data.filters.date_basis', 'deadline')
            ->assertJsonPath('data.period', ['basis' => 'deadline', 'label' => 'Deadline', 'from' => null, 'to' => null, 'active' => false]);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?date_basis=created_at&date_from=2026-11-01&date_to=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.period', ['basis' => 'created_at', 'label' => 'Tanggal dibuat', 'from' => '2026-11-01', 'to' => '2026-11-30', 'active' => true]);
    }

    public function test_task_created_and_due_in_different_months_follows_the_chosen_basis(): void
    {
        $this->task($this->marketing, $this->staff, TaskStatus::Todo, TaskPriority::High, '2026-12-10', 'Created Nov due Dec', '2026-11-03 09:00:00');
        $this->task($this->finance, null, TaskStatus::Todo, TaskPriority::Low, '2026-11-20', 'Created Oct due Nov', '2026-10-15 09:00:00');

        $november = 'date_from=2026-11-01&date_to=2026-11-30';

        $this->assertSame(1, $this->countInPeriod('created_at', $november, 'Created Nov due Dec'));
        $this->assertSame(0, $this->countInPeriod('deadline', $november, 'Created Nov due Dec'));
        $this->assertSame(0, $this->countInPeriod('created_at', $november, 'Created Oct due Nov'));
        $this->assertSame(1, $this->countInPeriod('deadline', $november, 'Created Oct due Nov'));

        // Every KPI and breakdown follows the same basis, not only Total Tasks.
        $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis=created_at&{$november}")
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 9)
            ->assertJsonPath('data.metrics.todo', 3)
            ->assertJsonPath('data.metrics.unfinished', 7)
            ->assertJsonPath('data.by_priority.high', ['total' => 2, 'unfinished' => 2])
            ->assertJsonPath('data.by_division.0.total', 2)
            ->assertJsonPath('data.by_division.1.total', 7);

        $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis=deadline&{$november}")
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 7)
            ->assertJsonPath('data.metrics.todo', 2)
            ->assertJsonPath('data.metrics.unfinished', 5)
            ->assertJsonPath('data.by_priority.high', ['total' => 0, 'unfinished' => 0])
            ->assertJsonPath('data.by_division.0.total', 2)
            ->assertJsonPath('data.by_division.1.total', 5);
    }

    public function test_overdue_and_due_today_stay_based_on_deadline_in_both_period_modes(): void
    {
        // Created in November but overdue since October: still Overdue when the period is on the creation date.
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?date_basis=created_at&date_from=2026-11-01&date_to=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.metrics.overdue', 3)
            ->assertJsonPath('data.metrics.due_today', 1)
            ->assertJsonPath('data.metrics.urgent', 2);

        // On the deadline basis that task falls outside November, so only November deadlines remain.
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?date_basis=deadline&date_from=2026-11-01&date_to=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.metrics.overdue', 2)
            ->assertJsonPath('data.metrics.due_today', 1);

        // A deadline period ending yesterday leaves no task due today.
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?date_basis=deadline&date_from=2026-11-01&date_to='.self::YESTERDAY)
            ->assertOk()
            ->assertJsonPath('data.metrics.overdue', 2)
            ->assertJsonPath('data.metrics.due_today', 0);
    }

    public function test_tasks_without_deadline_appear_only_on_the_created_at_basis(): void
    {
        $period = 'date_from=2026-11-01&date_to=2026-11-30&status=in_progress';

        $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis=created_at&{$period}")
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 1)
            ->assertJsonPath('data.metrics.overdue', 0)
            ->assertJsonPath('data.metrics.due_today', 0);

        $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis=deadline&{$period}")
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 0);
    }

    public function test_period_basis_combines_with_division_status_and_priority_filters(): void
    {
        $this->task($this->marketing, $this->staff, TaskStatus::Todo, TaskPriority::High, '2026-12-10', 'Created Nov due Dec', '2026-11-03 09:00:00');
        $this->task($this->finance, null, TaskStatus::Todo, TaskPriority::Low, '2026-11-20', 'Created Oct due Nov', '2026-10-15 09:00:00');

        $november = 'date_from=2026-11-01&date_to=2026-11-30';

        $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis=created_at&{$november}&division=MKT&status=todo&priority=high")
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 1);
        $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis=deadline&{$november}&division=MKT&status=todo&priority=high")
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 0);

        $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis=deadline&{$november}&division=FIN")
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 2)
            ->assertJsonPath('data.metrics.completed', 1)
            ->assertJsonPath('data.metrics.overdue', 0)
            ->assertJsonPath('data.metrics.completion_rate', 50);
        $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis=created_at&{$november}&division=FIN")
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 2)
            ->assertJsonPath('data.metrics.overdue', 1);
    }

    public function test_done_tasks_and_tasks_without_deadline_are_never_overdue_or_due_today(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?status=done')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 2)
            ->assertJsonPath('data.metrics.overdue', 0)
            ->assertJsonPath('data.metrics.due_today', 0)
            ->assertJsonPath('data.metrics.urgent', 0);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?status=in_progress')
            ->assertOk()
            ->assertJsonPath('data.metrics.overdue', 0)
            ->assertJsonPath('data.metrics.due_today', 0);
    }

    public function test_empty_result_returns_zeros_without_division_by_zero(): void
    {
        Task::query()->delete();

        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 0)
            ->assertJsonPath('data.metrics.overdue', 0)
            ->assertJsonPath('data.metrics.completion_rate', 0)
            ->assertJsonPath('data.by_division', [])
            ->assertJsonPath('data.by_priority.urgent', ['total' => 0, 'unfinished' => 0]);
    }

    public function test_breakdowns_by_division_and_priority(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/tasks/monitoring')->assertOk();

        $response->assertJsonPath('data.by_division', [
            ['code_division' => 'FIN', 'name' => 'Finance', 'total' => 2, 'completed' => 1, 'unfinished' => 1, 'overdue' => 1, 'completion_rate' => 50],
            ['code_division' => 'MKT', 'name' => 'Marketing', 'total' => 6, 'completed' => 1, 'unfinished' => 5, 'overdue' => 2, 'completion_rate' => 16.67],
        ]);
        $response->assertJsonPath('data.by_priority', [
            'low' => ['total' => 2, 'unfinished' => 1],
            'medium' => ['total' => 2, 'unfinished' => 2],
            'high' => ['total' => 1, 'unfinished' => 1],
            'urgent' => ['total' => 3, 'unfinished' => 2],
        ]);
    }

    public function test_status_or_deadline_changes_are_reflected(): void
    {
        $this->financeTodo->update(['status' => TaskStatus::Done, 'completed_at' => now()]);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring')
            ->assertOk()
            ->assertJsonPath('data.metrics.completed', 3)
            ->assertJsonPath('data.metrics.overdue', 2);

        $this->financeTodo->update(['status' => TaskStatus::Todo, 'deadline' => self::TODAY]);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring')
            ->assertOk()
            ->assertJsonPath('data.metrics.overdue', 2)
            ->assertJsonPath('data.metrics.due_today', 2);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?scope=all&status=archived&priority=critical&division=XXX&date_basis=updated_at&date_from=06-11-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scope', 'status', 'priority', 'division', 'date_basis', 'date_from']);

        $this->actingAs($this->admin)
            ->getJson('/api/tasks/monitoring?date_from='.self::TODAY.'&date_to='.self::YESTERDAY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
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

    private function countInPeriod(string $basis, string $period, string $title): int
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/tasks/monitoring?date_basis={$basis}&{$period}&search=".urlencode($title))
            ->assertOk()
            ->json('data.metrics.total_tasks');
    }

    private function task(Division $division, ?Account $pic, TaskStatus $status, TaskPriority $priority, ?string $deadline, string $title, ?string $createdAt = null): Task
    {
        return Task::factory()->create([
            'created_at' => $createdAt ?? now(),
            'title' => $title,
            'division_id' => $division->id,
            'pic_id' => $pic?->id,
            'creator_id' => $this->manager->id,
            'status' => $status,
            'priority' => $priority,
            'start_date' => null,
            'deadline' => $deadline,
            'requires_review' => true,
            'evidence_required' => false,
        ]);
    }
}
