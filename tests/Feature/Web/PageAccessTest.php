<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageAccessTest extends TestCase
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
            'fullname' => 'Siti Employee',
            'username' => 'employee',
            'password' => 'Employee123!',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/settings')->assertRedirect(route('login'));
        $this->get('/divisions')->assertRedirect(route('login'));
    }

    public function test_guest_can_open_login_page_without_demo_hint(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('api.login'), false)
            ->assertDontSee('Forgot Password?')
            ->assertSee('data-redirect="'.route('dashboard').'"', false)
            ->assertDontSee('Mode demo');
    }

    public function test_authenticated_account_is_redirected_away_from_login(): void
    {
        $this->actingAs($this->manager)
            ->get('/login')
            ->assertRedirect(route('dashboard'));
    }

    public function test_landing_page_shows_logged_in_account(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Siti Employee')
            ->assertSee('"username":"employee"', false)
            ->assertSee(route('settings.index'));
    }

    public function test_manager_can_open_settings_page_with_roles_and_divisions(): void
    {
        Division::create(['code_division' => 'OPS', 'name' => 'Operasional']);

        $this->actingAs($this->manager)
            ->get('/settings')
            ->assertOk()
            ->assertSee('Settings')
            ->assertSeeInOrder(['Total Accounts', 'Manager', 'Employee', 'Divisions'])
            ->assertSeeInOrder(['Account List', 'Create Account'])
            ->assertSee('@click="openCreate()"', false)
            ->assertSee('id="create-user-title"', false)
            ->assertDontSee('role="tablist"', false)
            ->assertDontSee('id="panel-create"', false)
            ->assertSee('Operasional')
            ->assertSee('>Actions</th>', false)
            ->assertSee('openEdit(user)', false)
            ->assertSee('id="edit-user-title"', false)
            ->assertDontSee('>Status</th>', false);
    }

    public function test_sidebar_links_settings_directly_for_manager(): void
    {
        $this->actingAs($this->manager)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Account Management', 'Logout'])
            ->assertSee('href="'.route('settings.index').'"', false)
            ->assertDontSee('Manajemen akun')
            ->assertDontSee("soon('Tasklist')", false);
    }

    public function test_sidebar_marks_settings_active_on_settings_page(): void
    {
        $this->actingAs($this->manager)
            ->get('/settings')
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    public function test_sidebar_links_settings_for_employee(): void
    {
        $this->actingAs($this->employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('href="'.route('settings.index').'"', false)
            ->assertDontSee("locked('Account Management')", false);
    }

    public function test_employee_can_open_settings_page_as_read_only(): void
    {
        $this->actingAs($this->employee)
            ->get('/settings')
            ->assertOk()
            ->assertSee('Account List')
            ->assertSee('Lihat daftar akun anggota tim beserta role dan divisinya.')
            ->assertSee('Cari akun anggota tim dan saring berdasarkan divisi.')
            ->assertSee('accountSettings(', false)
            ->assertDontSee('Create Account')
            ->assertDontSee('openCreate()', false)
            ->assertDontSee('id="create-user-title"', false)
            ->assertDontSee('id="panel-create"', false)
            ->assertDontSee('role="tablist"', false)
            ->assertDontSee('>Actions</th>', false)
            ->assertDontSee('openEdit(user)', false)
            ->assertDontSee('openDelete(user)', false)
            ->assertDontSee('id="edit-user-title"', false)
            ->assertDontSee('id="delete-user-title"', false)
            ->assertDontSee('>Status</th>', false);
    }

    public function test_manager_can_open_division_page_with_management_controls(): void
    {
        $this->actingAs($this->manager)
            ->get('/divisions')
            ->assertOk()
            ->assertSee('Division Management')
            ->assertSeeInOrder(['Total Divisions', 'Division Members', 'Empty Divisions'])
            ->assertSeeInOrder(['Division List', 'Create Division'])
            ->assertSee('id="create-division-title"', false)
            ->assertSee('Delete Division?');
    }

    public function test_employee_can_open_division_page_read_only(): void
    {
        $this->actingAs($this->employee)
            ->get('/divisions')
            ->assertOk()
            ->assertSee('Division List')
            ->assertDontSee('Create Division')
            ->assertDontSee('Delete Division?');
    }

    public function test_sidebar_links_division_page_for_every_role(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get('/dashboard')
                ->assertOk()
                ->assertSee('Division Management')
                ->assertSee('href="'.route('divisions.index').'"', false);
        }
    }

    public function test_guest_cannot_open_product_overview_page(): void
    {
        $this->get('/performance/products')->assertRedirect(route('login'));
    }

    public function test_every_role_can_open_product_overview_page_with_filters_and_cards(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get('/performance/products')
                ->assertOk()
                ->assertSee('Product Overview')
                ->assertSeeInOrder(['Source App / Platform', 'Year', 'Month'])
                ->assertSeeInOrder([
                    'Top Packages by Revenue',
                    'Top Packages by Transaction Volume',
                    'Package Performance Details',
                ])
                ->assertSee('Cari nama paket')
                ->assertSeeInOrder(['Excel', 'CSV'])
                ->assertSee('Data belum tersedia');
        }
    }

    public function test_sidebar_links_product_overview_inside_performance_folder(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance/products')
            ->assertOk()
            ->assertSeeInOrder(['Performance Analysis', 'Products'])
            ->assertSee('href="'.route('performance.products').'"', false)
            ->assertSee('aria-current="page"', false);
    }
}
