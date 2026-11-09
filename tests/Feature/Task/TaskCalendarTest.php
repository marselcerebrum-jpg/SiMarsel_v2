<?php

namespace Tests\Feature\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\ScheduleEvent;
use App\Models\Task;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TaskCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '/api/tasks/calendar?month=2026-10';

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

        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->seed(DatabaseSeeder::class);

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);

        $this->staffTask = $this->task('Update landing page', $this->marketing, $this->staff, '2026-10-15');
        $this->otherStaffTask = $this->task('Desain banner', $this->marketing, $this->otherStaff, '2026-10-22');
        $this->financeTask = $this->task('Laporan bulanan', $this->finance, null, '2026-10-31');
    }

    // Akses

    public function test_guest_cannot_open_the_calendar(): void
    {
        $this->getJson(self::MONTH)->assertUnauthorized();
    }

    public function test_admin_sees_the_deadlines_of_every_division(): void
    {
        $this->assertSame(
            [$this->staffTask->id, $this->otherStaffTask->id, $this->financeTask->id],
            $this->calendar($this->admin)->assertOk()->json('data.deadlines.*.id'),
        );
    }

    public function test_a_manager_only_sees_the_deadlines_of_the_division_they_manage(): void
    {
        $this->assertSame([$this->staffTask->id, $this->otherStaffTask->id], $this->calendar($this->manager)->json('data.deadlines.*.id'));
    }

    public function test_staff_only_see_the_deadlines_of_tasks_assigned_to_them(): void
    {
        $this->assertSame([$this->staffTask->id], $this->calendar($this->staff)->json('data.deadlines.*.id'));
    }

    public function test_a_division_filter_never_widens_the_deadline_scope(): void
    {
        $this->actingAs($this->staff)->getJson(self::MONTH.'&division=FIN')->assertOk()->assertJsonCount(0, 'data.deadlines');
        $this->actingAs($this->manager)->getJson(self::MONTH.'&division=FIN')->assertOk()->assertJsonCount(0, 'data.deadlines');
        $this->assertSame(
            [$this->financeTask->id],
            $this->actingAs($this->admin)->getJson(self::MONTH.'&division=FIN')->json('data.deadlines.*.id'),
        );
    }

    public function test_the_calendar_is_read_only(): void
    {
        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->actingAs($this->admin)->{$method}(self::MONTH)->assertMethodNotAllowed();
        }
    }

    // Deadline

    public function test_a_deadline_carries_the_task_as_in_the_tasklist_with_its_description(): void
    {
        $deadline = $this->calendar($this->staff)->json('data.deadlines.0');

        $this->assertSame([
            'id' => $this->staffTask->id,
            'title' => 'Update landing page',
            'division' => ['code_division' => 'MKT', 'name' => 'Marketing'],
            'pic' => ['id' => $this->staff->id, 'fullname' => 'Mkt Staff'],
            'priority' => 'high',
            'status' => 'in_progress',
            'deadline' => '2026-10-15',
            'can_view_detail' => true,
            'description' => 'Rincian tugas',
            'is_overdue' => true,
        ], collect($deadline)->only(['id', 'title', 'division', 'pic', 'priority', 'status', 'deadline', 'description', 'is_overdue', 'can_view_detail'])->all());

        $this->assertSame(
            $this->actingAs($this->staff)->getJson('/api/tasks')->json('data.0.deadline'),
            $deadline['deadline'],
        );
    }

    public function test_a_passed_deadline_is_overdue_only_while_the_task_is_still_active(): void
    {
        $done = $this->task('Sudah selesai', $this->marketing, null, '2026-10-10', TaskStatus::Done);
        $dueToday = $this->task('Deadline hari ini', $this->marketing, null, '2026-10-20');

        $overdue = collect($this->calendar($this->admin)->json('data.deadlines'))->pluck('is_overdue', 'id');

        $this->assertTrue($overdue[$this->staffTask->id]);
        $this->assertFalse($overdue[$done->id]);
        $this->assertFalse($overdue[$dueToday->id]);
        $this->assertFalse($overdue[$this->otherStaffTask->id]);
    }

    public function test_only_deadlines_inside_the_month_are_listed_and_tasks_without_one_are_left_out(): void
    {
        $firstDay = $this->task('Hari pertama', $this->marketing, null, '2026-10-01');
        $this->task('Bulan sebelumnya', $this->marketing, null, '2026-09-30');
        $this->task('Bulan berikutnya', $this->marketing, null, '2026-11-01');
        $this->task('Tanpa deadline', $this->marketing, null, null);

        $this->assertSame(
            [$firstDay->id, $this->staffTask->id, $this->otherStaffTask->id, $this->financeTask->id],
            $this->calendar($this->admin)->json('data.deadlines.*.id'),
        );
    }

    // Agenda bersama

    public function test_agendas_follow_is_shared_and_their_target_divisions_not_their_owner(): void
    {
        $sales = Division::create(['code_division' => 'SLS', 'name' => 'Sales']);
        $financeStaff = $this->account(Role::EMPLOYEE, 'fin.staff', $this->finance);

        $shared = $this->event(['owner_division_id' => $this->finance->id, 'start_date' => '2026-10-05']);
        $forMarketing = $this->internal([$this->marketing], ['owner_division_id' => $this->marketing->id, 'start_date' => '2026-10-06']);
        $forSalesAndMarketing = $this->internal([$sales, $this->marketing], ['owner_division_id' => $this->finance->id, 'start_date' => '2026-10-07']);
        $adminOnly = $this->internal([], ['owner_division_id' => null, 'start_date' => '2026-10-08']);

        $this->assertSame([$shared->id, $forMarketing->id, $forSalesAndMarketing->id, $adminOnly->id], $this->eventIds($this->admin));
        $this->assertSame([$shared->id, $forMarketing->id, $forSalesAndMarketing->id], $this->eventIds($this->manager));
        $this->assertSame([$shared->id, $forMarketing->id, $forSalesAndMarketing->id], $this->eventIds($this->staff));
        $this->assertSame([$shared->id, $forSalesAndMarketing->id], $this->eventIds($this->account(Role::EMPLOYEE, 'sls.staff', $sales)));
        // Finance owns the Sales and Marketing agenda but is not one of its targets.
        $this->assertSame([$shared->id], $this->eventIds($financeStaff));
    }

    public function test_an_agenda_carries_its_general_information_and_opens_a_related_task_only_for_whoever_may_view_it(): void
    {
        $related = $this->event([
            'title' => 'Tutup buku',
            'description' => 'Semua divisi menyerahkan laporan.',
            'owner_division_id' => $this->finance->id,
            'start_date' => '2026-10-28',
            'end_date' => '2026-10-30',
            'task_id' => $this->financeTask->id,
        ]);

        $this->assertSame([
            'id' => $related->id,
            'title' => 'Tutup buku',
            'division' => ['code_division' => 'FIN', 'name' => 'Finance'],
            'target_divisions' => [],
            'start_date' => '2026-10-28',
            'end_date' => '2026-10-30',
            'description' => 'Semua divisi menyerahkan laporan.',
            'task_id' => $this->financeTask->id,
            'can_view_detail' => false,
        ], $this->calendar($this->staff)->json('data.events.0'));

        $this->assertTrue($this->calendar($this->admin)->json('data.events.0.can_view_detail'));
    }

    public function test_an_internal_agenda_lists_its_target_divisions(): void
    {
        $sales = Division::create(['code_division' => 'SLS', 'name' => 'Sales']);
        $this->internal([$sales, $this->marketing], ['owner_division_id' => $this->finance->id, 'start_date' => '2026-10-07']);

        $event = $this->calendar($this->staff)->json('data.events.0');

        $this->assertSame(['code_division' => 'FIN', 'name' => 'Finance'], $event['division']);
        $this->assertSame([
            ['code_division' => 'MKT', 'name' => 'Marketing'],
            ['code_division' => 'SLS', 'name' => 'Sales'],
        ], $event['target_divisions']);
    }

    public function test_agendas_overlapping_the_month_edges_are_included_and_the_rest_left_out(): void
    {
        $fromLastMonth = $this->event(['start_date' => '2026-09-29', 'end_date' => '2026-10-01']);
        $intoNextMonth = $this->event(['start_date' => '2026-10-31', 'end_date' => '2026-11-02']);
        $this->event(['start_date' => '2026-09-30', 'end_date' => null]);
        $this->event(['start_date' => '2026-11-01', 'end_date' => '2026-11-03']);

        $this->assertSame([$fromLastMonth->id, $intoNextMonth->id], $this->eventIds($this->admin));
    }

    public function test_a_division_filter_keeps_the_agendas_it_owns_or_is_a_target_of(): void
    {
        $general = $this->event(['owner_division_id' => null, 'start_date' => '2026-10-06']);
        $ownedByMarketing = $this->event(['owner_division_id' => $this->marketing->id, 'start_date' => '2026-10-07']);
        $forMarketing = $this->internal([$this->marketing], ['owner_division_id' => $this->finance->id, 'start_date' => '2026-10-08']);
        $this->event(['owner_division_id' => $this->finance->id, 'start_date' => '2026-10-08']);
        $forFinance = $this->internal([$this->finance], ['owner_division_id' => $this->marketing->id, 'start_date' => '2026-10-09']);

        $this->assertSame(
            [$general->id, $ownedByMarketing->id, $forMarketing->id],
            collect($this->actingAs($this->manager)->getJson(self::MONTH.'&division=MKT')->json('data.events'))->pluck('id')->all(),
        );
        // Admin may see the Finance-only agenda Marketing owns, so it is kept for Admin.
        $this->assertContains(
            $forFinance->id,
            collect($this->actingAs($this->admin)->getJson(self::MONTH.'&division=MKT')->json('data.events'))->pluck('id')->all(),
        );
    }

    public function test_an_empty_month_returns_empty_lists(): void
    {
        $this->actingAs($this->staff)->getJson('/api/tasks/calendar?month=2027-01')
            ->assertOk()
            ->assertExactJson(['data' => ['events' => [], 'deadlines' => []]]);
    }

    // Validasi

    public function test_the_month_is_required_and_validated(): void
    {
        $this->actingAs($this->admin)->getJson('/api/tasks/calendar')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month' => 'Bulan wajib diisi.']);

        foreach (['2026-13', '10-2026', '2026-10-01'] as $month) {
            $this->actingAs($this->admin)->getJson("/api/tasks/calendar?month={$month}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['month' => 'Bulan harus berformat YYYY-MM.']);
        }

        $this->actingAs($this->admin)->getJson(self::MONTH.'&division=XXX')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division' => 'Divisi tidak ditemukan.']);
    }

    private function calendar(Account $account): TestResponse
    {
        return $this->actingAs($account)->getJson(self::MONTH);
    }

    /**
     * @return array<int, int>
     */
    private function eventIds(Account $account): array
    {
        return collect($this->calendar($account)->json('data.events'))->pluck('id')->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes): ScheduleEvent
    {
        return ScheduleEvent::factory()->create(['end_date' => null, ...$attributes]);
    }

    /**
     * @param  array<int, Division>  $targets
     * @param  array<string, mixed>  $attributes
     */
    private function internal(array $targets, array $attributes): ScheduleEvent
    {
        return ScheduleEvent::factory()->internalFor($targets)->create(['end_date' => null, ...$attributes]);
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

    private function task(string $title, Division $division, ?Account $pic, ?string $deadline, TaskStatus $status = TaskStatus::InProgress): Task
    {
        return Task::factory()->create([
            'title' => $title,
            'description' => 'Rincian tugas',
            'division_id' => $division->id,
            'pic_id' => $pic?->id,
            'priority' => TaskPriority::High,
            'status' => $status,
            'start_date' => null,
            'deadline' => $deadline,
        ]);
    }
}
