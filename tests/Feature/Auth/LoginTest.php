<?php

namespace Tests\Feature\Auth;

use App\Models\Account;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeder_creates_default_manager_account(): void
    {
        $account = Account::where('username', 'manager')->first();

        $this->assertNotNull($account);
        $this->assertSame('MGR', $account->role->code_role);
        $this->assertNull($account->division_id);
        $this->assertTrue(Hash::check('Manager123!', $account->password));
    }

    public function test_csrf_cookie_endpoint_sets_xsrf_token(): void
    {
        $this->get('/api/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_account_can_login_with_valid_credentials(): void
    {
        $this->get('/api/csrf-cookie');
        $oldSessionId = session()->getId();

        $this->postJson('/api/login', [
            'username' => 'manager',
            'password' => 'Manager123!',
        ])
            ->assertOk()
            ->assertJson([
                'message' => 'Login berhasil.',
                'data' => [
                    'fullname' => 'Default Manager',
                    'username' => 'manager',
                    'role' => ['code_role' => 'MGR', 'name' => 'Manager'],
                    'division' => null,
                ],
            ])
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs(Account::where('username', 'manager')->first());
        $this->assertNotSame($oldSessionId, session()->getId());
    }

    public function test_wrong_password_returns_error_message(): void
    {
        $this->postJson('/api/login', [
            'username' => 'manager',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username' => 'Username atau password salah.']);

        $this->assertGuest();
    }

    public function test_unknown_username_returns_same_error_message(): void
    {
        $this->postJson('/api/login', [
            'username' => 'nobody',
            'password' => 'Manager123!',
        ])->assertJsonValidationErrors(['username' => 'Username atau password salah.']);

        $this->assertGuest();
    }

    public function test_username_format_is_validated(): void
    {
        $this->postJson('/api/login', [
            'username' => 'ab',
            'password' => 'Manager123!',
        ])->assertJsonValidationErrors('username');

        $this->postJson('/api/login', [
            'username' => 'man ager!',
            'password' => 'Manager123!',
        ])->assertJsonValidationErrors('username');

        $this->assertGuest();
    }

    public function test_username_and_password_are_required(): void
    {
        $this->postJson('/api/login', [])
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_login_is_throttled_after_too_many_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['username' => 'manager', 'password' => 'wrong-password']);
        }

        $this->postJson('/api/login', ['username' => 'manager', 'password' => 'Manager123!'])
            ->assertTooManyRequests();
    }

    public function test_authenticated_account_can_logout(): void
    {
        $account = Account::where('username', 'manager')->first();

        $this->actingAs($account)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logout berhasil.']);

        $this->assertGuest();
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->post('/api/logout')->assertUnauthorized();
    }
}
