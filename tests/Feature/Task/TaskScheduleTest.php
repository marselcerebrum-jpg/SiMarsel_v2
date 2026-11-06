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
use Database\Seeders\ScheduleEventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TaskScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '/api/tasks/schedule?from=2026-10-12&to=2026-10-18';

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $staff;

    private Task $staffTask;

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

        $this->staffTask = $this->task('Update landing page', $this->marketing, $this->staff, '2026-10-13', '2026-10-15');
        $this->financeTask = $this->task('Laporan bulanan', $this->finance, null, '2026-10-10', '2026-10-20');
    }

    // Akses

    public function test_guest_cannot_load_the_schedule(): void
    {
        $this->getJson(self::PERIOD)->assertUnauthorized();
    }

    public function test_every_role_sees_the_tasks_of_every_division(): void
    {
        foreach ([$this->admin, $this->manager, $this->staff] as $account) {
            $this->assertSame(
                [$this->financeTask->id, $this->staffTask->id],
                $this->schedule($account)->assertOk()->json('data.*.id'),
            );
        }
    }

    public function test_an_item_only_opens_its_detail_for_whoever_may_view_the_task(): void
    {
        $this->assertSame([true, true], $this->detailAccess($this->admin));
        $this->assertSame([false, true], $this->detailAccess($this->manager));
        $this->assertSame([false, true], $this->detailAccess($this->staff));

        $this->actingAs($this->staff)->getJson("/api/tasks/{$this->financeTask->id}")->assertForbidden();
    }

    public function test_an_item_carries_only_its_schedule_and_not_the_task_internals(): void
    {
        $item = $this->schedule($this->staff)->json('data.1');

        $this->assertSame([
            'id' => $this->staffTask->id,
            'type' => 'task',
            'title' => 'Update landing page',
            'division' => ['code_division' => 'MKT', 'name' => 'Marketing'],
            'start_date' => '2026-10-13',
            'end_date' => '2026-10-15',
            'status' => 'in_progress',
            'priority' => 'high',
            'task_id' => $this->staffTask->id,
            'can_view_detail' => true,
        ], $item);
    }

    public function test_the_schedule_is_read_only(): void
    {
        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->actingAs($this->admin)->{$method}(self::PERIOD)->assertMethodNotAllowed();
        }
    }

    // Periode

    public function test_tasks_overlapping_the_period_edges_are_included_and_the_rest_left_out(): void
    {
        $endsOnFirstDay = $this->task('Selesai di hari pertama', $this->marketing, null, '2026-10-01', '2026-10-12');
        $startsOnLastDay = $this->task('Mulai di hari terakhir', $this->marketing, null, '2026-10-18', '2026-10-30');
        $this->task('Sebelum periode', $this->marketing, null, '2026-10-01', '2026-10-11');
        $this->task('Sesudah periode', $this->marketing, null, '2026-10-19', '2026-10-25');

        $ids = $this->schedule($this->admin)->json('data.*.id');

        $this->assertSame([$endsOnFirstDay->id, $this->financeTask->id, $this->staffTask->id, $startsOnLastDay->id], $ids);
    }

    public function test_a_task_with_one_date_is_placed_on_that_day_and_one_without_dates_is_left_out(): void
    {
        $deadlineOnly = $this->task('Hanya deadline', $this->marketing, null, null, '2026-10-16');
        $startOnly = $this->task('Hanya mulai', $this->marketing, null, '2026-10-14', null);
        $this->task('Hanya mulai di luar periode', $this->marketing, null, '2026-10-20', null);
        $this->task('Tanpa tanggal', $this->marketing, null, null, null);

        $data = collect($this->schedule($this->admin)->json('data'))->keyBy('id');

        $this->assertEqualsCanonicalizing(
            [$this->financeTask->id, $this->staffTask->id, $deadlineOnly->id, $startOnly->id],
            $data->keys()->all(),
        );
        $this->assertSame([null, '2026-10-16'], [$data[$deadlineOnly->id]['start_date'], $data[$deadlineOnly->id]['end_date']]);
        $this->assertSame(['2026-10-14', null], [$data[$startOnly->id]['start_date'], $data[$startOnly->id]['end_date']]);
    }

    public function test_items_are_ordered_by_the_day_they_start(): void
    {
        $deadlineOnly = $this->task('Deadline tanggal 12', $this->marketing, null, null, '2026-10-12');
        $event = ScheduleEvent::factory()->create(['start_date' => '2026-10-11', 'end_date' => '2026-10-13']);

        $this->assertSame(
            [['task', $this->financeTask->id], ['event', $event->id], ['task', $deadlineOnly->id], ['task', $this->staffTask->id]],
            collect($this->schedule($this->admin)->json('data'))->map(fn (array $item) => [$item['type'], $item['id']])->all(),
        );
    }

    // Filter divisi

    public function test_a_division_focuses_the_tasks_and_keeps_the_agendas_of_every_division(): void
    {
        $allDivisions = ScheduleEvent::factory()->create(['start_date' => '2026-10-14', 'division_id' => null]);
        $marketingEvent = ScheduleEvent::factory()->create(['start_date' => '2026-10-15', 'division_id' => $this->marketing->id]);
        ScheduleEvent::factory()->create(['start_date' => '2026-10-15', 'division_id' => $this->finance->id]);

        $data = $this->actingAs($this->admin)->getJson(self::PERIOD.'&division=MKT')->assertOk()->json('data');

        $this->assertSame(
            [['task', $this->staffTask->id], ['event', $allDivisions->id], ['event', $marketingEvent->id]],
            collect($data)->map(fn (array $item) => [$item['type'], $item['id']])->all(),
        );
    }

    public function test_without_a_division_the_schedule_covers_every_division(): void
    {
        $this->schedule($this->admin)->assertJsonCount(2, 'data');
    }

    // Agenda bersama

    public function test_a_shared_agenda_is_visible_to_every_role_and_an_internal_one_only_to_its_division_and_admin(): void
    {
        $shared = ScheduleEvent::factory()->create(['start_date' => '2026-10-14', 'division_id' => $this->finance->id, 'is_shared' => true]);
        $financeInternal = ScheduleEvent::factory()->create(['start_date' => '2026-10-14', 'division_id' => $this->finance->id, 'is_shared' => false]);
        $marketingInternal = ScheduleEvent::factory()->create(['start_date' => '2026-10-14', 'division_id' => $this->marketing->id, 'is_shared' => false]);

        $this->assertEqualsCanonicalizing([$shared->id, $financeInternal->id, $marketingInternal->id], $this->eventIds($this->admin));
        $this->assertEqualsCanonicalizing([$shared->id, $marketingInternal->id], $this->eventIds($this->manager));
        $this->assertEqualsCanonicalizing([$shared->id, $marketingInternal->id], $this->eventIds($this->staff));
    }

    public function test_an_agenda_opens_the_detail_of_its_related_task_only_for_whoever_may_view_that_task(): void
    {
        $standalone = ScheduleEvent::factory()->create(['title' => 'Rapat koordinasi', 'start_date' => '2026-10-14', 'end_date' => null]);
        $related = ScheduleEvent::factory()->create(['start_date' => '2026-10-15', 'task_id' => $this->financeTask->id]);

        $events = fn (Account $account) => collect($this->schedule($account)->json('data'))->where('type', 'event')->keyBy('id');

        $this->assertSame([
            'id' => $standalone->id,
            'type' => 'event',
            'title' => 'Rapat koordinasi',
            'division' => null,
            'start_date' => '2026-10-14',
            'end_date' => null,
            'status' => null,
            'priority' => null,
            'task_id' => null,
            'can_view_detail' => false,
        ], $events($this->admin)[$standalone->id]);
        $this->assertTrue($events($this->admin)[$related->id]['can_view_detail']);
        $this->assertFalse($events($this->staff)[$related->id]['can_view_detail']);
    }

    public function test_agendas_overlapping_the_period_edges_are_included_and_the_rest_left_out(): void
    {
        $endsOnFirstDay = ScheduleEvent::factory()->create(['start_date' => '2026-10-10', 'end_date' => '2026-10-12']);
        $oneDayOnLastDay = ScheduleEvent::factory()->create(['start_date' => '2026-10-18', 'end_date' => null]);
        ScheduleEvent::factory()->create(['start_date' => '2026-10-11', 'end_date' => null]);
        ScheduleEvent::factory()->create(['start_date' => '2026-10-19', 'end_date' => '2026-10-20']);

        $this->assertEqualsCanonicalizing([$endsOnFirstDay->id, $oneDayOnLastDay->id], $this->eventIds($this->admin));
    }

    public function test_the_seeder_places_demo_agendas_around_the_current_week(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->seed(ScheduleEventSeeder::class);

        $this->assertSame(count(ScheduleEventSeeder::SHARED_AGENDAS) + 2, ScheduleEvent::count());
        $this->assertSame(3, collect($this->schedule($this->staff)->json('data'))->where('type', 'event')->count());

        $this->seed(ScheduleEventSeeder::class);
        $this->assertSame(count(ScheduleEventSeeder::SHARED_AGENDAS) + 2, ScheduleEvent::count());
    }

    // Validasi

    public function test_the_period_is_required_and_validated(): void
    {
        $this->actingAs($this->admin)->getJson('/api/tasks/schedule')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from' => 'Tanggal mulai wajib diisi.', 'to' => 'Tanggal akhir wajib diisi.']);

        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=14-10-2026&to=2026-10-20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from' => 'Tanggal mulai harus berformat YYYY-MM-DD.']);

        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=2026-10-20&to=2026-10-14')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'Tanggal akhir tidak boleh sebelum Tanggal mulai.']);

        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=2026-10-12&to=2026-10-18&division=XXX')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division' => 'Divisi tidak ditemukan.']);
    }

    public function test_the_period_is_limited_to_a_maximum_number_of_days(): void
    {
        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=2026-10-01&to=2026-12-01')->assertOk();

        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=2026-10-01&to=2026-12-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'Periode maksimal 62 hari.']);
    }

    private function schedule(Account $account): TestResponse
    {
        return $this->actingAs($account)->getJson(self::PERIOD);
    }

    /**
     * can_view_detail of the Finance task and of the Marketing task, in schedule order.
     *
     * @return array<int, bool>
     */
    private function detailAccess(Account $account): array
    {
        return $this->schedule($account)->json('data.*.can_view_detail');
    }

    /**
     * @return array<int, int>
     */
    private function eventIds(Account $account): array
    {
        return collect($this->schedule($account)->json('data'))->where('type', 'event')->pluck('id')->all();
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

    private function task(string $title, Division $division, ?Account $pic, ?string $startDate, ?string $deadline): Task
    {
        return Task::factory()->create([
            'title' => $title,
            'description' => 'Rincian internal',
            'division_id' => $division->id,
            'pic_id' => $pic?->id,
            'priority' => TaskPriority::High,
            'status' => $pic ? TaskStatus::InProgress : TaskStatus::Backlog,
            'start_date' => $startDate,
            'deadline' => $deadline,
        ]);
    }
}
