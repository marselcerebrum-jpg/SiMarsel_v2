<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoPageTest extends TestCase
{
    use RefreshDatabase;

    private Account $manager;

    private Account $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Sales Marketing',
            'username' => 'sales',
            'password' => 'Employee123!',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/promo')->assertRedirect(route('login'));
    }

    public function test_every_role_can_open_promo_page_with_both_tabs(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get(route('promo.index'))
                ->assertOk()
                ->assertSee('Promo Management')
                ->assertSee('x-data="promoManagement"', false)
                ->assertSeeInOrder(['role="tablist"', 'Promo Codes', 'Master Data'], false)
                ->assertSee('id="panel-promo-codes"', false)
                ->assertSee('id="panel-master-data"', false);
        }
    }

    public function test_promo_codes_tab_provides_analysis_and_management_area(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder(['Promo Code Analysis', 'Promo Code List']);
    }

    public function test_promo_analysis_filter_offers_platform_payment_method_search_and_two_period_modes(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoAnalysis"', false)
            ->assertSee('@promo-analysis-errors="showServerErrors($event.detail)"', false)
            ->assertSeeInOrder(['Platform / Source App', 'Payment Method', 'Promo Code', 'Period'])
            ->assertSee('id="promo-analysis-month"', false)
            ->assertSee('id="promo-analysis-year"', false)
            ->assertSee('id="promo-analysis-start"', false)
            ->assertSee('id="promo-analysis-end"', false)
            ->assertSee('@click="resetFilter()"', false)
            ->assertSee('tanggal bayar Markaz');
    }

    public function test_promo_analysis_kpi_cards_follow_the_shared_filter(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoAnalysisSummary"', false)
            ->assertSee('@promo-analysis-filter.window="load($event.detail)"', false)
            ->assertSeeInOrder([
                'Total Transactions with Promo',
                'Total Transactions without Promo',
                'Revenue with Promo',
                'Revenue without Promo',
                'Promo Transaction Percentage',
                'Promo Codes Used',
            ])
            ->assertDontSee('Active Codes')
            ->assertDontSee('Kode Aktif');
    }

    public function test_promo_funnel_table_follows_the_shared_filter_inside_the_analysis_section(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'x-data="promoAnalysisSummary"',
                'x-data="promoAnalysisFunnel"',
                'Promo Code List',
            ], false)
            ->assertSee('@promo-analysis-filter.window="load($event.detail)"', false)
            ->assertSeeInOrder(['Promo Analysis by Funnel', 'Funnel', 'Jumlah Transaksi', 'Jumlah Revenue', 'Kontribusi Funnel (%)'])
            ->assertSee('Memuat rekap funnel');
    }

    public function test_promo_source_table_follows_the_shared_filter_after_the_funnel_table(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'x-data="promoAnalysisFunnel"',
                'x-data="promoAnalysisSource"',
                'Promo Code List',
            ], false)
            ->assertSeeInOrder(['Promo Analysis by Source', 'Source', 'Jumlah Penggunaan', 'Jumlah Revenue', 'Kontribusi Source (%)'])
            ->assertSee('Jumlah penggunaan dapat melebihi jumlah transaksi unik karena satu transaksi dapat memakai dua kode promo.')
            ->assertSee('Memuat rekap source');
    }

    public function test_promo_code_summary_follows_the_shared_filter_and_opens_a_read_only_usage_detail(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder([
                'x-data="promoAnalysis"',
                'x-data="promoAnalysisSource"',
                'x-data="promoAnalysisCode"',
                'x-data="promoAnalysisCodeUsage"',
                'Promo Code List',
            ], false)
            ->assertSee('@promo-code-usage-open.window', false)
            ->assertSeeInOrder(['Summary by Promo Code', 'Kode Promo', 'Platform', 'Kategori/Funnel', 'Source', 'Jumlah Penggunaan', 'Jumlah Revenue', 'Kontribusi (%)'])
            ->assertSee('Memuat rekap kode promo')
            ->assertSee('Promo Code Usage Details')
            ->assertSeeInOrder(['Tanggal', 'Platform/Aplikasi', 'Jumlah Penggunaan', 'Revenue', 'Total']);

        $summary = str($response->getContent())->between('id="promo-code-summary-title"', 'Promo Code List');
        $this->assertStringNotContainsString('Tipe', $summary);
        $this->assertStringNotContainsString('Import', $summary);
    }

    public function test_promo_code_list_shows_mapping_columns_with_search_and_pagination(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoCodeManagement"', false)
            ->assertSeeInOrder(['Promo Code', 'Platform', 'Funnel', 'Source', 'Usage', 'Actions'])
            ->assertSee('Cari kode, platform, funnel, atau source')
            ->assertSee('All Platforms')
            ->assertSee('aria-label="Promo Code Pagination"', false)
            ->assertSeeInOrder(['Previous', 'Next']);
    }

    public function test_single_entry_form_has_required_code_and_platform_and_optional_funnel_source(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('Add Promo Code')
            ->assertSee('id="promo-code-form-title"', false)
            ->assertSeeInOrder(['for="promo-code-code"', 'for="promo-code-platform"', 'for="promo-code-funnel"', 'for="promo-code-source"'], false)
            ->assertSee('Opsional, boleh dikosongkan jika belum diketahui.')
            ->assertSee('Kode yang sama boleh dipakai di platform berbeda.');
    }

    public function test_promo_code_form_has_no_type_status_or_effective_date_field(): void
    {
        $response = $this->actingAs($this->employee)->get(route('promo.index'));

        foreach (['Code Type', 'Tipe Kode', 'Inactive', 'Nonaktif', 'is_active', 'Effective Date', 'Tanggal Efektif'] as $text) {
            $response->assertDontSee($text, false);
        }

        // The analysis Date Range filter has date inputs; the promo code form itself must not.
        $this->view('pages.promo.components.promo-code-form-modal')->assertDontSee('type="date"', false);
    }

    public function test_funnel_or_source_change_asks_for_confirmation_before_saving(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('Confirm Funnel/Source Change')
            ->assertSee('Perubahan Funnel/Sumber berlaku untuk penggunaan setelah perubahan disimpan.')
            ->assertSee('Transaksi historis yang sudah terklasifikasi tidak akan berubah. Lanjutkan?')
            ->assertSeeInOrder(['@click="cancelConfirm()"', 'Cancel', '@click="save()"', 'Continue'], false);
    }

    public function test_promo_code_delete_explains_used_codes_cannot_be_deleted(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('Delete Promo Code?')
            ->assertSee('sehingga tidak dapat dihapus.');
    }

    public function test_bulk_entry_has_spreadsheet_columns_without_type_or_status(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('@click="$dispatch(\'promo-bulk-open\')"', false)
            ->assertSee('x-data="promoCodeBulkEntry"', false)
            ->assertSee('id="promo-bulk-title"', false)
            ->assertSeeInOrder(['Promo Code', 'Platform', 'Funnel', 'Source', 'Status'])
            ->assertSeeInOrder(['Add Row', 'Add 5 Rows', 'Close', 'Save All']);

        foreach (['Code Type', 'Tipe Kode', 'Inactive', 'Nonaktif', 'is_active'] as $text) {
            $response->assertDontSee($text, false);
        }
    }

    public function test_bulk_entry_shows_succeeded_and_failed_counts(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSeeInOrder(['Processed', 'Succeeded', 'Failed'])
            ->assertSee('Baris yang sudah tersimpan tidak dikirim ulang.')
            ->assertSee('@promo-codes-changed.window="reload()"', false);
    }

    public function test_unregistered_code_alert_shows_raw_code_platform_and_usage(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoUnregisteredCodes"', false)
            ->assertSeeInOrder(['Unregistered Promo Codes', 'Promo Code List'])
            ->assertSeeInOrder(['Raw Code', 'Platform', 'Usage', 'Last Used', 'Actions'])
            ->assertSee('Register Code')
            ->assertSee('Transaksi dengan kode ini tetap tersimpan dan tetap dihitung dalam analisis.');
    }

    public function test_registering_from_alert_opens_prefilled_single_entry(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('@promo-code-register.window="openRegister($event.detail)"', false)
            ->assertSee('@promo-codes-changed.window="refresh()"', false)
            ->assertSee('Register Promo Code')
            ->assertSee('Mendaftarkan kode dari alert juga dapat melengkapi')
            ->assertSee('penggunaan historis yang sebelumnya belum terpetakan akan dihubungkan')
            ->assertSee('Hanya penggunaan yang belum terhubung ke kode promo yang diproses; penggunaan yang sudah terhubung tidak diproses ulang meskipun funnel/source-nya kosong. Klasifikasi Funnel/Source yang sudah ada tidak akan ditimpa.')
            ->assertDontSee('Deactivate');
    }

    public function test_master_data_tab_manages_funnel_and_source_separately(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee("promoMasterTable('funnel', 'Funnel')", false)
            ->assertSee("promoMasterTable('source', 'Source')", false)
            ->assertSeeInOrder(['Funnel List', 'Create Funnel', 'Source List', 'Create Source'])
            ->assertSee('id="funnel-form-title"', false)
            ->assertSee('id="source-form-title"', false)
            ->assertSeeInOrder(['Delete Funnel?', 'Delete Source?']);
    }

    public function test_platform_list_is_read_only(): void
    {
        $this->actingAs($this->employee)
            ->get(route('promo.index'))
            ->assertSee('x-data="promoPlatformTable"', false)
            ->assertSeeInOrder(['Platform List', 'Read Only'])
            ->assertDontSee('Create Platform')
            ->assertDontSee('Edit Platform')
            ->assertDontSee('Delete Platform');
    }

    public function test_sidebar_links_promo_page_for_every_role(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get(route('promo.index'))
                ->assertSeeInOrder(['Sales &amp; Marketing', 'Promo Management'], false)
                ->assertSee('href="'.route('promo.index').'"', false)
                ->assertSee('aria-current="page"', false);
        }
    }
}
