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
                'New Users',
                'Unique Users',
                'AOV',
                'Repeat Order',
                'Conversion',
            ])
            ->assertDontSee('Period Comparison');
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

    public function test_performance_page_has_platform_contribution_section(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSee('Platform Contribution')
            ->assertSee('x-data="performanceContribution"', false)
            ->assertSee('@performance-filter.window="loadContribution($event.detail)"', false)
            ->assertDontSee('contributionCanvas', false)
            ->assertSeeInOrder(['>Platform</th>', '>Revenue</th>', '>Transactions</th>', '>Users</th>', '>Contribution (%)</th>'], false)
            ->assertSeeInOrder(['Trend &amp; Performance Analysis', 'Platform Contribution'], false);
    }

    public function test_platform_contribution_shows_summary_progress_bar_tooltip_and_states(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSeeInOrder(['Total Revenue', 'Platforms', 'Jumlah platform yang tercakup dalam hasil filter'])
            ->assertSee('x-text="contributionRevenue"', false)
            ->assertSee('x-text="contributionPlatformCount"', false)
            ->assertSee(':style="{ width: row.width }"', false)
            ->assertSee('@mouseenter="showTip(row, $event)"', false)
            ->assertSee('role="tooltip"', false)
            ->assertSee("contributionState === 'loading'", false)
            ->assertSee("contributionState === 'empty'", false)
            ->assertSee("contributionState === 'error'", false)
            ->assertSee('@click="retryContribution()"', false);
    }

    public function test_performance_page_has_payment_method_section_after_platform_contribution(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSee('Payment Methods')
            ->assertSee('x-data="performancePaymentMethod(', false)
            ->assertSee('@performance-filter.window="loadPayment($event.detail)"', false)
            ->assertSee('aria-label="Payment Method Parameter"', false)
            ->assertSeeInOrder(["setPaymentParameter('revenue')", "setPaymentParameter('transactions')"], false)
            ->assertSeeInOrder(['Platform Contribution', 'Payment Methods'], false);
    }

    public function test_payment_method_section_shows_pie_chart_legend_and_states(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
            ->assertOk()
            ->assertSee('x-ref="paymentCanvas"', false)
            ->assertSee(':aria-label="paymentChartLabel"', false)
            ->assertSeeInOrder(['>Payment Method</th>', '>Transactions</th>', '>Revenue</th>', '>Contribution (%)</th>'], false)
            ->assertSee('@mouseenter="highlightPayment(index)"', false)
            ->assertSee('x-text="paymentTotals.revenue"', false)
            ->assertSee("paymentState === 'loading'", false)
            ->assertSee("paymentState === 'empty'", false)
            ->assertSee("paymentState === 'error'", false)
            ->assertSee("paymentState === 'unavailable'", false)
            ->assertSee('Data belum tersedia')
            ->assertSee('@click="retryPayment()"', false);
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
