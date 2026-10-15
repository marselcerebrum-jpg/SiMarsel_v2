<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformancePageTest extends TestCase
{
    use RefreshDatabase;

    private Account $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);

        $this->employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/performance')->assertRedirect(route('login'));
    }

    public function test_performance_page_shows_every_kpi_in_order(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSee('Performance Summary')
            ->assertSee('performanceSummary', false)
            ->assertSeeInOrder([
                'Filter',
                'Total Revenue',
                'Total Transactions',
                'Total Downloader',
                'User Baru',
                'Unique Users',
                'AOV',
                'Repeat Order',
                'Conversion',
                'Period Comparison',
            ]);
    }

    public function test_performance_page_has_period_and_platform_filters(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSee('id="filter-preset"', false)
            ->assertSee('id="filter-start"', false)
            ->assertSee('id="filter-end"', false)
            ->assertSee('id="filter-platform"', false)
            ->assertSee('All Platforms');
    }

    public function test_platform_filter_lists_platforms_from_database(): void
    {
        Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JadiASN']);
        Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'Cerebrum']);

        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSeeInOrder(['cerebrum', 'Cerebrum', 'jadiasn', 'JadiASN']);
    }

    public function test_performance_page_has_trend_analysis_controls(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSee('Trend &amp; Performance Analysis', false)
            ->assertSee('performanceTrend', false)
            ->assertSee('performance-filter.window', false)
            ->assertSee('aria-label="Metric"', false)
            ->assertSee('aria-label="Data Period"', false)
            ->assertSee('id="trend-chart"', false)
            ->assertSeeInOrder(['Revenue', 'Transactions', 'Downloader', 'Conversion', 'User Baru', 'Repeat Order'])
            ->assertSeeInOrder(["setMetric('revenue')", "setMetric('transactions')", "setMetric('downloaders')", "setMetric('conversion')", "setMetric('new_users')", "setMetric('repeat_orders')"], false)
            ->assertSeeInOrder(["setPeriod('daily')", "setPeriod('weekly')", "setPeriod('monthly')"], false)
            ->assertSeeInOrder(['>Bar<', '>Line<', '>Area<', '>Pie<', '>Table<'], false)
            ->assertSeeInOrder(['>Daily<', '>Weekly<', '>Monthly<'], false);
    }

    public function test_sidebar_links_performance_page_and_marks_it_active(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSeeInOrder(['Analytics', 'Performance Summary', 'System'])
            ->assertSee('href="'.route('performance.index').'"', false)
            ->assertSee('aria-current="page"', false);
    }
}
