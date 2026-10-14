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
        $this->get('/')->assertRedirect('/performance');
        $this->get('/performance')->assertRedirect(route('login'));
        $this->get('/settings')->assertRedirect(route('login'));
        $this->get('/divisions')->assertRedirect(route('login'));
    }

    public function test_guest_can_open_login_page_without_demo_hint(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('api.login'), false)
            ->assertDontSee('Forgot Password?')
            ->assertSee('data-redirect="'.route('performance.index').'"', false)
            ->assertDontSee('Mode demo');
    }

    public function test_authenticated_account_is_redirected_away_from_login(): void
    {
        $this->actingAs($this->manager)
            ->get('/login')
            ->assertRedirect(route('performance.index'));
    }

    public function test_old_dashboard_page_no_longer_exists(): void
    {
        $this->actingAs($this->manager)
            ->get('/dashboard')
            ->assertNotFound();
    }

    public function test_landing_page_shows_logged_in_account(): void
    {
        $this->actingAs($this->employee)
            ->get('/performance')
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
            ->assertSeeInOrder(['Create Account', 'Account List'])
            ->assertSee('Operasional')
            ->assertSee('>Actions</th>', false)
            ->assertSee('openEdit(user)', false)
            ->assertSee('id="edit-user-title"', false)
            ->assertDontSee('>Status</th>', false);
    }

    public function test_sidebar_links_settings_directly_for_manager(): void
    {
        $this->actingAs($this->manager)
            ->get('/performance')
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
            ->get('/performance')
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
            ->assertSee('Manajemen Divisi')
            ->assertSeeInOrder(['Total Divisi', 'Anggota Divisi', 'Divisi Kosong'])
            ->assertSeeInOrder(['Buat Divisi', 'Daftar Divisi'])
            ->assertSee('Hapus Divisi?');
    }

    public function test_employee_can_open_division_page_read_only(): void
    {
        $this->actingAs($this->employee)
            ->get('/divisions')
            ->assertOk()
            ->assertSee('Daftar Divisi')
            ->assertDontSee('Buat Divisi')
            ->assertDontSee('Hapus Divisi?');
    }

    public function test_sidebar_links_division_page_for_every_role(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get('/performance')
                ->assertOk()
                ->assertSee('Division Management')
                ->assertSee('href="'.route('divisions.index').'"', false);
        }
    }
}
