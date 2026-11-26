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

    public function test_sales_summary_shows_seven_metrics_in_two_blocks_and_a_supporting_row(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['x-data="performanceSummary" @performance-filter.window="loadSummary($event.detail)"', 'x-data="performanceSalesSummary"'], false)
            ->assertDontSee('applySalesSummaryFilter', false)
            ->assertSeeInOrder([
                'Ringkasan Sales',
                'Hasil Penjualan',
                'Berdasarkan tanggal pembayaran',
                'Omzet',
                'Transaksi Berhasil',
                'Aktivitas Transaksi',
                'Berdasarkan tanggal transaksi dibuat',
                'Total Transaksi',
                'Konversi Transaksi',
                'Rata-rata Nilai Order',
                'Pembeli Unik',
                'Downloader',
            ])
            ->assertDontSee('Pengguna Baru')
            ->assertDontSee('Repeat Order')
            ->assertDontSee('Period Comparison');
    }

    public function test_each_sales_summary_card_shows_its_comparison_and_labelled_change_under_the_active_value(): void
    {
        $response = $this->actingAs($this->employee)->get('/dashboard')->assertOk();

        $response->assertSeeInOrder([
            'data-sales-summary-metric="revenue"',
            'x-text="salesCards.revenue.value"',
            'Pembanding:',
            'x-text="salesCards.revenue.comparison"',
            'Perubahan:',
            'aria-hidden="true" x-text="salesCards.revenue.change.symbol"',
            'class="sr-only" x-text="salesCards.revenue.change.label"',
            'x-text="salesCards.revenue.note"',
            'x-text="salesCards.revenue.detail"',
        ], false);

        $this->assertSame(7, substr_count($response->getContent(), 'data-sales-summary-metric='));
    }

    public function test_sales_summary_error_offers_coba_lagi_for_the_same_filter(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('x-text="salesSummaryError"', false)
            ->assertSee('@click="retrySalesSummary()"', false)
            ->assertSeeInOrder(['x-text="salesSummaryError"', 'Coba Lagi'], false);
    }

    public function test_dashboard_has_global_platform_period_and_comparison_filters(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('x-data="dashboardFilter(', false)
            ->assertSee('@performance-period="receivePeriod($event.detail)"', false)
            ->assertSee('id="filter-platforms-label"', false)
            ->assertSee('aria-controls="filter-platforms-options"', false)
            ->assertSee('@change="selectAllPlatforms()"', false)
            ->assertSee('@change="togglePlatform(platform.value)"', false)
            ->assertSee('Semua Platform')
            ->assertSee('id="filter-preset"', false)
            ->assertSee('@change="changePreset()"', false)
            ->assertSee('id="filter-comparison"', false)
            ->assertSee('@change="changeComparison()"', false)
            ->assertSeeInOrder(['Platform', 'Periode', 'Pembanding'])
            ->assertDontSee('id="filter-mode"', false)
            ->assertDontSee('toggleYear(year)', false);
    }

    public function test_custom_ranges_have_labelled_inputs_with_errors_under_them(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['x-if="isDateRange"', 'Rentang Tanggal', 'for="filter-start"', 'Tanggal Mulai', 'for="filter-end"', 'Tanggal Akhir', 'aria-describedby="filter-end-error"', 'id="filter-end-error"'], false)
            ->assertSee('x-model="filter.date_to"', false)
            ->assertSee(':aria-invalid="Boolean(filterErrors.date_to).toString()"', false)
            ->assertSeeInOrder(['x-if="isCustomComparison"', 'Rentang Pembanding Khusus', 'for="filter-comparison-start"', 'Tanggal Mulai Pembanding', 'for="filter-comparison-end"', 'Tanggal Akhir Pembanding', 'id="filter-comparison-end-error"'], false)
            ->assertSee('x-text="filterErrors.comparison_to"', false);
    }

    public function test_filter_shows_the_resolved_period_status_and_empty_data_notice(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('x-text="!filterPanelOpen ? filterStatus : \'\'"', false)
            ->assertSeeInOrder(['Rentang aktif', 'periodInfo?.provisionalLabel', 'Rentang pembanding', 'Batas lengkap', 'Catatan pembanding', 'Rentang yang dipakai untuk perubahan', 'Durasi'], false)
            ->assertSee('x-show="emptyMessage"', false)
            ->assertSeeInOrder(['data-dashboard-section="filter"', 'Rentang aktif', 'x-text="emptyMessage"', 'data-dashboard-section="sales-summary"'], false);
    }

    public function test_cards_shimmer_and_tables_show_a_spinner_while_loading(): void
    {
        $response = $this->actingAs($this->employee)->get('/dashboard')->assertOk();
        $html = $response->getContent();

        $this->assertSame(7, substr_count($html, 'x-show="salesSummaryLoading"'), 'Every Ringkasan Sales value turns into shimmer bars.');
        $this->assertSame(7, substr_count($html, 'x-show="!salesSummaryLoading"'));
        $this->assertSame(3, substr_count($html, 'x-show="contributionLoading"'), 'Two contribution tile values shimmer, its table shows a spinner.');
        $this->assertSame(18, substr_count($html, 'animate-shimmer'));
        $response->assertDontSee('blur-[2px]', false);

        foreach (['contributionLoading', 'paymentLoading', 'purchaseTimeLoading'] as $state) {
            $response->assertSee('x-show="'.$state.'"', false);
        }
        $response->assertSee('x-show="trendLoading && !hasPoints"', false);
        $this->assertSame(6, substr_count($html, 'size-8 animate-spin'), 'Three tables, plus three first-load table placeholders.');
        $response->assertDontSee('Loading && contributionState', false);
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
            ->assertSeeInOrder(['Ekspor Excel', 'Ekspor CSV']);
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
            ->assertSee('Analisis Tren &amp; Performa', false)
            ->assertSee('x-data="performanceTrend"', false)
            ->assertSee('@performance-filter.window="applyFilter($event.detail)"', false)
            ->assertSeeInOrder(['aria-label="Pengelompokan"', 'aria-label="Legenda grafik tren"', 'x-ref="canvas"', 'Waktu aktif', 'Waktu pembanding'], false)
            ->assertSee('@click="setGranularity(option.value)"', false)
            ->assertSee(':disabled="isGranularityDisabled(option.value)"', false)
            ->assertSee('@click="toggleSeries(item.key)"', false)
            ->assertSee(':aria-pressed="isSeriesVisible(item.key).toString()"', false)
            ->assertSee('@keydown.arrow-right.prevent="movePoint(1)"', false)
            ->assertSee('@keydown.arrow-left.prevent="movePoint(-1)"', false)
            ->assertDontSee('aria-label="Metrik"', false)
            ->assertDontSee('id="trend-chart"', false);
    }

    public function test_performance_page_has_platform_contribution_section(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Kontribusi Platform')
            ->assertSee('x-data="performanceContribution"', false)
            ->assertSee('@performance-filter.window="loadContribution($event.detail)"', false)
            ->assertDontSee('contributionCanvas', false)
            ->assertSeeInOrder(['>Platform</th>', '>Revenue</th>', '>Transaksi</th>', '>Pengguna</th>', '>Contribution (%)</th>'], false)
            ->assertSeeInOrder(['Analisis Tren &amp; Performa', 'Kontribusi Platform'], false);
    }

    public function test_platform_contribution_shows_summary_progress_bar_tooltip_and_states(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Total Revenue', 'Platform', 'Jumlah platform yang tercakup dalam hasil filter'])
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
            ->assertSee('Metode Pembayaran')
            ->assertSee('x-data="performancePaymentMethod(', false)
            ->assertSee('@performance-filter.window="loadPayment($event.detail)"', false)
            ->assertSee('aria-label="Parameter Metode Pembayaran"', false)
            ->assertSeeInOrder(["setPaymentParameter('revenue')", "setPaymentParameter('transactions')"], false)
            ->assertSeeInOrder(['Kontribusi Platform', 'Metode Pembayaran'], false);
    }

    public function test_payment_method_section_shows_pie_chart_legend_and_states(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('x-ref="paymentCanvas"', false)
            ->assertSee(':aria-label="paymentChartLabel"', false)
            ->assertSeeInOrder(['>Metode Pembayaran</th>', '>Transaksi</th>', '>Revenue</th>', '>Contribution (%)</th>'], false)
            ->assertSee('@mouseenter="highlightPayment(index)"', false)
            ->assertSee('x-text="paymentTotals.revenue"', false)
            ->assertSee("paymentState === 'loading'", false)
            ->assertSee("paymentState === 'empty'", false)
            ->assertSee("paymentState === 'error'", false)
            ->assertDontSee("paymentState === 'unavailable'", false)
            ->assertSee('@click="retryPayment()"', false);
    }

    public function test_performance_page_has_purchase_time_section_after_payment_methods(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Waktu Pembelian')
            ->assertSee('x-data="performancePurchaseTime(', false)
            ->assertSee('@performance-filter.window="loadPurchaseTime($event.detail)"', false)
            ->assertSeeInOrder(['Dini Hari', 'Pagi', 'Siang', 'Sore', 'Malam', 'Tidak Diketahui'])
            ->assertSeeInOrder(['Metode Pembayaran', 'Waktu Pembelian'], false);
    }

    public function test_purchase_time_section_shows_pie_chart_legend_and_states(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('x-ref="purchaseTimeCanvas"', false)
            ->assertSee(':aria-label="purchaseTimeChartLabel"', false)
            ->assertSeeInOrder(['>Rentang Waktu</th>', '>Jam</th>', '>Transaksi</th>', '>Porsi (%)</th>'], false)
            ->assertSee('@mouseenter="highlightPurchaseTime(index)"', false)
            ->assertSee('x-text="purchaseTimeTotal"', false)
            ->assertSee("purchaseTimeState === 'loading'", false)
            ->assertSee("purchaseTimeState === 'empty'", false)
            ->assertSee("purchaseTimeState === 'error'", false)
            ->assertSee('@click="retryPurchaseTime()"', false);
    }

    public function test_sidebar_links_performance_page_and_marks_it_active(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Analitik', 'Dashboard', 'Pekerjaan', 'Sistem'])
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_page_and_browser_tab_are_titled_dashboard(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('<title>Dashboard — simarsel</title>', false)
            ->assertSee('<h1 class="text-2xl font-bold tracking-tight text-ink">Dashboard</h1>', false)
            ->assertDontSee('Ringkasan Performa');
    }

    public function test_dashboard_sections_follow_the_reading_order(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder([
                'data-dashboard-section="filter"',
                'data-dashboard-section="sales-summary"',
                'data-dashboard-section="trend"',
                'data-dashboard-section="platform-contribution"',
                'data-dashboard-section="downloader"',
                'data-dashboard-section="transaction-status"',
                'data-dashboard-section="product-type"',
                'data-dashboard-section="payment-method"',
                'data-dashboard-section="product-performance"',
                'data-dashboard-section="purchase-time"',
                'data-dashboard-section="transactions"',
            ], false)
            ->assertSeeInOrder(['Ringkasan Sales', 'Downloader', 'Status Transaksi', 'Perdana vs Perpanjangan', 'Metode Pembayaran', 'Performa Produk', 'Waktu Pembelian', 'Daftar Transaksi']);
    }

    public function test_paired_blocks_stack_on_phones_and_sit_side_by_side_on_wide_screens(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['data-dashboard-section="sales-summary"', 'class="mt-3 grid gap-4 lg:grid-cols-2"', 'data-sales-summary-block="sales-result"', 'class="mt-3 grid gap-3 sm:grid-cols-2"', 'data-sales-summary-block="transaction-activity"', 'class="mt-3 grid gap-3 sm:grid-cols-2"', 'data-sales-summary-block="supporting"'], false)
            ->assertSeeInOrder(['class="mt-4 grid gap-4 lg:grid-cols-2"', 'data-dashboard-section="transaction-status"', 'data-dashboard-section="product-type"'], false);
    }

    public function test_compact_header_keeps_the_active_period_and_an_accessible_filter_button(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('x-data="dashboardShell"', false)
            ->assertSeeInOrder(['data-dashboard-compact-header', 'x-text="activeRangeLabel || \'Memuat periode…\'"', 'aria-label="Buka panel filter dashboard"'], false)
            ->assertSee('aria-controls="dashboard-filter-panel"', false)
            ->assertSee(':aria-expanded="filterPanelOpen.toString()"', false)
            ->assertSeeInOrder(['id="dashboard-filter-panel"', 'x-dialog="filterPanelOpen"', 'aria-modal="true"', '@keydown.escape.stop="closeFilterPanel()"', 'data-dashboard-slot="filter-panel"'], false)
            ->assertSeeInOrder(['data-dashboard-slot="filter-panel"', 'id="panel-filter-platforms-label"', 'id="panel-filter-preset"', 'id="panel-filter-comparison"', 'for="panel-filter-end"', 'x-text="filterPanelOpen ? filterStatus : \'\'"', 'Catatan pembanding'], false)
            ->assertSeeInOrder(['data-dashboard-compact-header', 'x-ref="pageHeader"'], false);
    }

    public function test_export_actions_sit_in_their_own_sections(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['x-ref="pageHeader"', 'aria-label="Ekspor Laporan Dashboard"', '</header>', 'data-dashboard-section="filter"'], false)
            ->assertSeeInOrder(['data-dashboard-section="transactions"', 'data-dashboard-slot="transactions-export"', 'Ekspor Daftar Transaksi'], false)
            ->assertSeeInOrder(['Ekspor Daftar Transaksi', '</main>'], false);
    }

    public function test_dashboard_does_not_load_the_legacy_products_frontend(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/api/products', false)
            ->assertDontSee('productOverview', false)
            ->assertDontSee('performance/products', false);
    }
}
