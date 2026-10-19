<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
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
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_performance_page_shows_every_kpi_in_order(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
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

    public function test_performance_page_has_overview_filters(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="filter-platforms-label"', false)
            ->assertSee('aria-multiselectable="true"', false)
            ->assertSee('togglePlatform(platform.value)', false)
            ->assertSee('All Platforms')
            ->assertSee('id="filter-mode"', false)
            ->assertSee('toggleYear(year)', false)
            ->assertSee('id="filter-month"', false)
            ->assertSee(':disabled="!monthEnabled"', false)
            ->assertSee('id="filter-year-from"', false)
            ->assertSee('id="filter-year-to"', false)
            ->assertSee('id="filter-start"', false)
            ->assertSee('id="filter-end"', false)
            ->assertSeeInOrder(['Platforms', 'Period', 'Years', 'Month', 'From Year', 'To Year', 'Start Date', 'End Date'])
            ->assertDontSee('id="filter-preset"', false);
    }

    public function test_filter_offers_at_least_the_last_five_years(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19));
        Transaction::factory()->create(['paid_date' => '2026-03-05']);

        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('[2026,2025,2024,2023,2022]', false);
    }

    public function test_filter_offers_years_back_to_the_first_transaction(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19));
        Transaction::factory()->create(['paid_date' => '2019-03-05']);

        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('[2026,2025,2024,2023,2022,2021,2020,2019]', false);
    }

    public function test_export_buttons_link_to_the_export_endpoint_with_the_filter_query(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(":href=\"exportLink('xlsx')\"", false)
            ->assertSee(":href=\"exportLink('csv')\"", false)
            ->assertSee('href="'.route('api.performance.export', ['format' => 'xlsx']).'"', false)
            ->assertSee('href="'.route('api.performance.export', ['format' => 'csv']).'"', false)
            ->assertSeeInOrder(['Export Excel', 'Export CSV']);
    }

    public function test_platform_filter_lists_platforms_from_database(): void
    {
        Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JadiASN']);
        Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'Cerebrum']);

        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['cerebrum', 'Cerebrum', 'jadiasn', 'JadiASN']);
    }

    public function test_performance_page_has_trend_analysis_controls(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Trend &amp; Performance Analysis', false)
            ->assertSee('performanceTrend', false)
            ->assertSee('performance-filter.window', false)
            ->assertSee('aria-label="Metric"', false)
            ->assertSee('aria-label="Data Period"', false)
            ->assertSee('id="trend-chart"', false)
            ->assertSeeInOrder(['Revenue', 'Transactions', 'Downloader', 'Conversion', 'New Users', 'Repeat Order'])
            ->assertSeeInOrder(["setMetric('revenue')", "setMetric('transactions')", "setMetric('downloaders')", "setMetric('conversion')", "setMetric('new_users')", "setMetric('repeat_orders')"], false)
            ->assertSeeInOrder(["setPeriod('daily')", "setPeriod('weekly')", "setPeriod('monthly')"], false)
            ->assertSeeInOrder(['>Bar<', '>Line<', '>Area<', '>Pie<', '>Table<'], false)
            ->assertSeeInOrder(['>Daily<', '>Weekly<', '>Monthly<'], false);
    }

    public function test_performance_page_has_platform_contribution_section(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
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
            ->get('/dashboard')
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
            ->get('/dashboard')
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
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('x-ref="paymentCanvas"', false)
            ->assertSee(':aria-label="paymentChartLabel"', false)
            ->assertSeeInOrder(['>Payment Method</th>', '>Transactions</th>', '>Revenue</th>', '>Contribution (%)</th>'], false)
            ->assertSee('@mouseenter="highlightPayment(index)"', false)
            ->assertSee('x-text="paymentTotals.revenue"', false)
            ->assertSee("paymentState === 'loading'", false)
            ->assertSee("paymentState === 'empty'", false)
            ->assertSee("paymentState === 'error'", false)
            ->assertDontSee("paymentState === 'unavailable'", false)
            ->assertSee('@click="retryPayment()"', false);
    }

    public function test_sidebar_links_performance_page_and_marks_it_active(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Analytics', 'Performance Analysis', 'Summary', 'Products', 'System'])
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('aria-current="page"', false);
    }
}
