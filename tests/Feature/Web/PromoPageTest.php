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

        foreach (['Code Type', 'Tipe Kode', 'Inactive', 'Nonaktif', 'is_active', 'Effective Date', 'Tanggal Efektif', 'type="date"'] as $text) {
            $response->assertDontSee($text, false);
        }
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
