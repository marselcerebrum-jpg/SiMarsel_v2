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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-14 (STEAM-249): the period and PIC filters of the task list, and the KPIs that follow them.
 */
class TaskDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    /**
     * @var array<string, Account>
     */
    private array $accounts;

    /**
     * @var array<string, Task>
     */
    private array $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-11-18 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->accounts = [
            'admin' => $this->account(Role::ADMIN, 'admin', null),
            'manager' => $this->account(Role::MANAGER, 'manager', $this->finance),
            'supervisor' => $this->account(Role::SUPERVISOR, 'supervisor', $this->marketing),
            'staff' => $this->account(Role::EMPLOYEE, 'staff', $this->marketing),
            'freelance' => $this->account(Role::FREELANCE, 'freelance', null),
        ];

        $this->tasks = [
            'multi' => $this->task('multi', '2026-11-01', '2026-11-30', ['staff', 'freelance'], ['status' => TaskStatus::Todo, 'priority' => TaskPriority::High]),
            'october' => $this->task('october', '2026-10-31', '2026-11-01', ['staff'], ['status' => TaskStatus::InProgress, 'priority' => TaskPriority::Low]),
            'last_year' => $this->task('last_year', '2025-11-15', '2025-11-20', [], ['division_id' => $this->finance->id, 'priority' => TaskPriority::High]),
            'leap' => $this->task('leap', '2028-02-29', '2028-03-01', ['supervisor'], ['division_id' => $this->finance->id]),
            'no_deadline' => $this->task('no_deadline', '2026-11-10', null, ['freelance']),
            'no_start' => $this->task('no_start', null, '2026-11-10', ['staff']),
            'year_end' => $this->task('year_end', '2026-12-31', '2027-01-01', ['freelance']),
        ];
    }

    public function test_guest_cannot_filter(): void
    {
        $this->getJson('/api/tasks?filter_mode=year&date_field=deadline&year=2026')->assertUnauthorized();
    }

    public function test_without_a_period_every_task_is_listed(): void
    {
        $this->assertCount(7, $this->titles());
    }

    public function test_a_single_date_matches_the_chosen_date_field(): void
    {
        $this->assertSame(['no_deadline'], $this->titles(['filter_mode' => 'date', 'date_field' => 'start_date', 'date' => '2026-11-10']));
        $this->assertSame(['no_start'], $this->titles(['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '2026-11-10']));
        $this->assertSame([], $this->titles(['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '2026-11-11']));
    }

    public function test_a_month_covers_its_first_and_last_day_of_the_chosen_year_only(): void
    {
        $this->assertSame(
            ['multi', 'no_deadline'],
            $this->titles(['filter_mode' => 'month', 'date_field' => 'start_date', 'month' => 11, 'year' => 2026]),
        );
        $this->assertSame(
            ['last_year'],
            $this->titles(['filter_mode' => 'month', 'date_field' => 'start_date', 'month' => 11, 'year' => 2025]),
        );
        $this->assertSame(
            ['multi', 'no_start', 'october'],
            $this->titles(['filter_mode' => 'month', 'date_field' => 'deadline', 'month' => 11, 'year' => 2026]),
        );
    }

    public function test_february_of_a_leap_year_includes_the_29th(): void
    {
        $this->assertSame(['leap'], $this->titles(['filter_mode' => 'month', 'date_field' => 'start_date', 'month' => 2, 'year' => 2028]));
        $this->assertSame([], $this->titles(['filter_mode' => 'month', 'date_field' => 'deadline', 'month' => 2, 'year' => 2028]));
        $this->assertSame(['leap'], $this->titles(['filter_mode' => 'month', 'date_field' => 'deadline', 'month' => 3, 'year' => 2028]));
    }

    public function test_a_year_covers_the_first_and_last_day_of_that_year(): void
    {
        $this->assertSame(
            ['multi', 'no_deadline', 'october', 'year_end'],
            $this->titles(['filter_mode' => 'year', 'date_field' => 'start_date', 'year' => 2026]),
        );
        $this->assertSame(['year_end'], $this->titles(['filter_mode' => 'year', 'date_field' => 'deadline', 'year' => 2027]));
    }

    public function test_a_range_includes_both_of_its_ends(): void
    {
        $this->assertSame(
            ['no_start', 'october'],
            $this->titles(['filter_mode' => 'range', 'date_field' => 'deadline', 'start_date' => '2026-11-01', 'end_date' => '2026-11-10']),
        );
        $this->assertSame(
            ['october'],
            $this->titles(['filter_mode' => 'range', 'date_field' => 'deadline', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01']),
        );
    }

    public function test_a_task_without_the_filtered_date_is_left_out(): void
    {
        $this->assertNotContains('no_start', $this->titles(['filter_mode' => 'year', 'date_field' => 'start_date', 'year' => 2026]));
        $this->assertNotContains('no_deadline', $this->titles(['filter_mode' => 'year', 'date_field' => 'deadline', 'year' => 2026]));
    }

    public function test_a_deleted_task_stays_out_of_the_period(): void
    {
        $this->tasks['no_start']->delete();

        $this->assertSame([], $this->titles(['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '2026-11-10']));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<int, string>  $errors
     */
    #[DataProvider('invalidPeriods')]
    public function test_an_invalid_period_is_rejected(array $query, array $errors): void
    {
        $this->actingAs($this->accounts['admin'])
            ->getJson('/api/tasks?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->actingAs($this->accounts['admin'])
            ->getJson('/api/tasks/monitoring?'.http_build_query(['scope' => 'tasklist', ...$query]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<int, string>}>
     */
    public static function invalidPeriods(): array
    {
        return [
            'unknown mode' => [['filter_mode' => 'week', 'date_field' => 'deadline'], ['filter_mode']],
            'unknown date field' => [['filter_mode' => 'year', 'date_field' => 'created_at', 'year' => 2026], ['date_field']],
            'mode without date field' => [['filter_mode' => 'year', 'year' => 2026], ['date_field']],
            'date field without mode' => [['date_field' => 'deadline'], ['filter_mode']],
            'value without mode' => [['date_field' => 'deadline', 'year' => 2026], ['filter_mode', 'year']],
            'date missing' => [['filter_mode' => 'date', 'date_field' => 'deadline'], ['date']],
            'date that does not exist' => [['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '2026-02-31'], ['date']],
            'date in another format' => [['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '10/11/2026'], ['date']],
            'date mode with a month' => [['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '2026-11-10', 'month' => 11], ['month']],
            'month without a year' => [['filter_mode' => 'month', 'date_field' => 'deadline', 'month' => 11], ['year']],
            'year missing the month' => [['filter_mode' => 'month', 'date_field' => 'deadline', 'year' => 2026], ['month']],
            'month 13' => [['filter_mode' => 'month', 'date_field' => 'deadline', 'month' => 13, 'year' => 2026], ['month']],
            'month 0' => [['filter_mode' => 'month', 'date_field' => 'deadline', 'month' => 0, 'year' => 2026], ['month']],
            'year out of bounds' => [['filter_mode' => 'year', 'date_field' => 'deadline', 'year' => 1999], ['year']],
            'year mode with a month' => [['filter_mode' => 'year', 'date_field' => 'deadline', 'year' => 2026, 'month' => 11], ['month']],
            'range without an end' => [['filter_mode' => 'range', 'date_field' => 'deadline', 'start_date' => '2026-11-01'], ['end_date']],
            'range without a start' => [['filter_mode' => 'range', 'date_field' => 'deadline', 'end_date' => '2026-11-01'], ['start_date']],
            'range ending before it starts' => [['filter_mode' => 'range', 'date_field' => 'deadline', 'start_date' => '2026-11-02', 'end_date' => '2026-11-01'], ['end_date']],
            'range mode with a date' => [['filter_mode' => 'range', 'date_field' => 'deadline', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'date' => '2026-11-01'], ['date']],
            'unknown pic' => [['pic_id' => 999999], ['pic_id']],
        ];
    }

    public function test_the_period_combines_with_division_pic_priority_status_and_search(): void
    {
        $year = ['filter_mode' => 'year', 'date_field' => 'start_date', 'year' => 2026];

        $this->assertSame(['multi', 'no_deadline', 'october', 'year_end'], $this->titles([...$year, 'division' => 'MKT']));
        $this->assertSame([], $this->titles([...$year, 'division' => 'FIN']));
        $this->assertSame(['multi', 'october'], $this->titles([...$year, 'pic_id' => $this->accounts['staff']->id]));
        $this->assertSame(['multi'], $this->titles([...$year, 'priority' => 'high']));
        $this->assertSame(['october'], $this->titles([...$year, 'status' => 'in_progress']));
        $this->assertSame(['year_end'], $this->titles([...$year, 'search' => 'year_']));
        $this->assertSame(['multi'], $this->titles([...$year, 'division' => 'MKT', 'pic_id' => $this->accounts['freelance']->id, 'priority' => 'high', 'status' => 'todo']));
    }

    public function test_the_pic_filter_matches_only_active_pics(): void
    {
        $staff = $this->accounts['staff'];
        $this->assertSame(['multi', 'no_start', 'october'], $this->titles(['pic_id' => $staff->id]));

        $this->tasks['multi']->activeAssignments()->where('account_id', $staff->id)->update(['unassigned_at' => now()]);

        $this->assertSame(['no_start', 'october'], $this->titles(['pic_id' => $staff->id]));
    }

    public function test_pagination_and_sorting_work_on_the_filtered_tasks(): void
    {
        $query = ['filter_mode' => 'year', 'date_field' => 'start_date', 'year' => 2026, 'sort' => 'start_date', 'direction' => 'desc', 'per_page' => 2];

        $first = $this->list([...$query, 'page' => 1])->assertOk();
        $second = $this->list([...$query, 'page' => 2])->assertOk();

        $this->assertSame(['year_end', 'no_deadline'], array_column($first->json('data'), 'title'));
        $this->assertSame(['multi', 'october'], array_column($second->json('data'), 'title'));
        $first->assertJsonPath('meta.total', 4);
        $this->assertStringContainsString('filter_mode=year', $first->json('links.next'));
        $this->assertSame(4, array_sum($first->json('meta.status_counts')));
    }

    public function test_my_tasks_holds_the_period_to_the_tasks_the_account_is_an_active_pic_of(): void
    {
        $period = ['filter_mode' => 'month', 'date_field' => 'deadline', 'month' => 11, 'year' => 2026];

        $this->assertSame(['multi', 'no_start', 'october'], $this->titles([...$period, 'scope' => 'all_tasks'], 'staff'));
        $this->assertSame(['multi', 'no_start', 'october'], $this->titles([...$period, 'scope' => 'my_tasks'], 'staff'));
        $this->assertSame(['multi'], $this->titles([...$period, 'scope' => 'my_tasks'], 'freelance'));
        $this->assertSame([], $this->titles([...$period, 'scope' => 'my_tasks'], 'manager'));

        $this->tasks['multi']->activeAssignments()->where('account_id', $this->accounts['freelance']->id)->update(['unassigned_at' => now()]);

        $this->assertSame([], $this->titles([...$period, 'scope' => 'my_tasks'], 'freelance'));
        $this->assertSame(['multi', 'no_start', 'october'], $this->titles([...$period, 'scope' => 'all_tasks'], 'freelance'));
    }

    public function test_every_role_reads_the_same_tasks_for_the_same_period(): void
    {
        $period = ['filter_mode' => 'year', 'date_field' => 'deadline', 'year' => 2026];

        foreach (array_keys($this->accounts) as $role) {
            $this->assertSame(['multi', 'no_start', 'october'], $this->titles($period, $role), $role);
        }
    }

    public function test_the_period_grants_nothing_and_changes_nothing(): void
    {
        $before = $this->tasks['multi']->fresh()->toArray();
        $all = collect($this->list([], 'staff')->json('data'))->firstWhere('id', $this->tasks['multi']->id);
        $filtered = collect($this->list(['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '2026-11-30'], 'staff')->json('data'))->firstWhere('id', $this->tasks['multi']->id);

        $this->assertSame($all, $filtered);
        $this->assertSame($before, $this->tasks['multi']->fresh()->toArray());
    }

    public function test_a_task_with_several_pics_counts_once_in_the_list_counts_and_kpis(): void
    {
        $query = ['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '2026-11-30'];

        $list = $this->list($query)->assertOk()->assertJsonCount(1, 'data');
        $list->assertJsonPath('meta.status_counts.todo', 1);

        $this->monitoring($query)
            ->assertOk()
            ->assertJsonPath('data.metrics.total_tasks', 1)
            ->assertJsonPath('data.metrics.todo', 1);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('kpiPeriods')]
    public function test_the_list_status_counts_and_kpis_show_the_same_numbers(array $query): void
    {
        foreach (['all_tasks', 'my_tasks'] as $scope) {
            foreach (['admin', 'staff', 'freelance'] as $role) {
                $list = $this->list([...$query, 'scope' => $scope], $role)->assertOk();
                $kpi = $this->monitoring([...$query, 'task_scope' => $scope], $role)->assertOk();
                $counts = $list->json('meta.status_counts');

                $this->assertSame(count($list->json('data')), $kpi->json('data.metrics.total_tasks'));
                $this->assertSame(array_sum($counts), $kpi->json('data.metrics.total_tasks'));
                $this->assertSame($counts['backlog'], $kpi->json('data.metrics.backlog'));
                $this->assertSame($counts['todo'], $kpi->json('data.metrics.todo'));
                $this->assertSame($counts['in_progress'], $kpi->json('data.metrics.on_process'));
                $this->assertSame($counts['done'], $kpi->json('data.metrics.completed'));
            }
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function kpiPeriods(): array
    {
        return [
            'date' => [['filter_mode' => 'date', 'date_field' => 'deadline', 'date' => '2026-11-10']],
            'month' => [['filter_mode' => 'month', 'date_field' => 'deadline', 'month' => 11, 'year' => 2026]],
            'year' => [['filter_mode' => 'year', 'date_field' => 'start_date', 'year' => 2026]],
            'range' => [['filter_mode' => 'range', 'date_field' => 'start_date', 'start_date' => '2026-10-31', 'end_date' => '2026-11-10']],
        ];
    }

    public function test_the_kpis_report_the_period_and_the_task_scope_they_counted(): void
    {
        $this->monitoring(['filter_mode' => 'month', 'date_field' => 'start_date', 'month' => 2, 'year' => 2028, 'task_scope' => 'my_tasks'], 'supervisor')
            ->assertOk()
            ->assertJsonPath('data.scope', ['key' => 'tasklist', 'label' => 'Tugas Saya'])
            ->assertJsonPath('data.period', ['basis' => 'start_date', 'label' => 'Tanggal mulai', 'from' => '2028-02-01', 'to' => '2028-02-29', 'active' => true])
            ->assertJsonPath('data.filters.period', ['field' => 'start_date', 'mode' => 'month', 'from' => '2028-02-01', 'to' => '2028-02-29'])
            ->assertJsonPath('data.metrics.total_tasks', 1);
    }

    public function test_the_kpis_refuse_two_kinds_of_period_and_my_tasks_outside_the_tasklist_scope(): void
    {
        $this->monitoring(['filter_mode' => 'year', 'date_field' => 'deadline', 'year' => 2026, 'date_basis' => 'deadline'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter_mode' => 'Filter periode tidak dapat digabung dengan date_basis, date_from, atau date_to.']);

        $this->actingAs($this->accounts['admin'])
            ->getJson('/api/tasks/monitoring?task_scope=my_tasks')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task_scope']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, string>
     */
    private function titles(array $query = [], string $role = 'admin'): array
    {
        $titles = array_column($this->list($query, $role)->assertOk()->json('data'), 'title');
        sort($titles);

        return $titles;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function list(array $query = [], string $role = 'admin'): TestResponse
    {
        return $this->actingAs($this->accounts[$role])->getJson('/api/tasks?'.http_build_query($query));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function monitoring(array $query = [], string $role = 'admin'): TestResponse
    {
        return $this->actingAs($this->accounts[$role])->getJson('/api/tasks/monitoring?'.http_build_query(['scope' => 'tasklist', ...$query]));
    }

    /**
     * @param  array<int, string>  $pics
     * @param  array<string, mixed>  $attributes
     */
    private function task(string $title, ?string $startDate, ?string $deadline, array $pics = [], array $attributes = []): Task
    {
        return Task::factory()
            ->withPics(...array_map(fn (string $key) => $this->accounts[$key], $pics))
            ->create([
                'title' => $title,
                'division_id' => $this->marketing->id,
                'creator_id' => $this->accounts['admin']->id,
                'status' => TaskStatus::Backlog,
                'priority' => TaskPriority::Medium,
                'start_date' => $startDate,
                'deadline' => $deadline,
                ...$attributes,
            ]);
    }

    private function account(string $role, string $username, ?Division $division): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $division?->id,
            'fullname' => $username,
            'username' => "t14.{$username}",
            'password' => 'Password123!',
        ]);
    }
}
