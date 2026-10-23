<?php

namespace Tests\Feature\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\DuplicateTaskSubmissionException;
use App\Models\Account;
use App\Models\Division;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Services\TaskService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCreationTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $staff;

    private Account $financeStaff;

    private TaskCategory $reviewed;

    private TaskCategory $unreviewed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-10-23 09:00:00');

        // The duplicate guard runs on the same shared, lock-capable store as production (CACHE_STORE=database).
        config(['cache.default' => 'database']);

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->financeStaff = $this->account(Role::EMPLOYEE, 'fin.staff', $this->finance);

        $this->reviewed = TaskCategory::where('name', 'Konten')->firstOrFail();
        $this->unreviewed = TaskCategory::where('name', 'Administrasi')->firstOrFail();
    }

    // Akses

    public function test_guest_cannot_create_a_task(): void
    {
        $this->postJson('/api/tasks', $this->payload())->assertUnauthorized();
        $this->getJson('/api/tasks/create-options')->assertUnauthorized();
    }

    public function test_staff_cannot_create_a_task(): void
    {
        $this->actingAs($this->staff)->postJson('/api/tasks', $this->payload())->assertForbidden();
        $this->actingAs($this->staff)->getJson('/api/tasks/create-options')->assertForbidden();

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_manager_without_a_division_cannot_create_a_task(): void
    {
        $manager = $this->account(Role::MANAGER, 'lonely.manager', null);

        $this->actingAs($manager)->postJson('/api/tasks', $this->payload())->assertForbidden();
    }

    public function test_admin_can_create_a_task_for_any_division(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/tasks', $this->payload([
                'division_id' => $this->finance->id,
                'pic_id' => $this->financeStaff->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('message', 'Tugas berhasil dibuat.')
            ->assertJsonPath('data.division.code_division', 'FIN')
            ->assertJsonPath('data.pic.id', $this->financeStaff->id);
    }

    public function test_manager_can_create_a_task_for_their_own_division(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.division.code_division', 'MKT');
    }

    public function test_manager_cannot_create_a_task_for_another_division(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['division_id' => $this->finance->id, 'pic_id' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'division_id' => 'Anda hanya dapat membuat tugas untuk divisi yang Anda kelola.',
            ]);

        $this->assertDatabaseCount('tasks', 0);
    }

    // Penyimpanan

    public function test_task_is_saved_with_its_relations_and_the_creator(): void
    {
        $platform = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);

        $response = $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload([
                'description' => 'Banner untuk promo November.',
                'category_id' => $this->reviewed->id,
                'platform_id' => $platform->id,
                'priority' => 'high',
                'start_date' => '2026-10-24',
                'deadline' => '2026-10-30',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Desain banner promo')
            ->assertJsonPath('data.category.name', 'Konten')
            ->assertJsonPath('data.platform.name', 'JADIASN')
            ->assertJsonPath('data.creator.id', $this->manager->id)
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.start_date', '2026-10-24')
            ->assertJsonPath('data.deadline', '2026-10-30');

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.id'),
            'title' => 'Desain banner promo',
            'description' => 'Banner untuk promo November.',
            'category_id' => $this->reviewed->id,
            'division_id' => $this->marketing->id,
            'platform_id' => $platform->id,
            'pic_id' => $this->staff->id,
            'creator_id' => $this->manager->id,
            'priority' => 'high',
        ]);
    }

    public function test_creator_cannot_be_spoofed_from_the_request(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['creator_id' => $this->admin->id]))
            ->assertCreated();

        $this->assertSame($this->manager->id, Task::sole()->creator_id);
    }

    public function test_only_title_and_division_are_required_for_a_backlog_task(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', ['title' => 'Ide konten', 'division_id' => $this->marketing->id])
            ->assertCreated()
            ->assertJsonPath('data.status', TaskStatus::Backlog->value)
            ->assertJsonPath('data.priority', TaskPriority::Medium->value)
            ->assertJsonPath('data.pic', null)
            ->assertJsonPath('data.requires_review', false);
    }

    public function test_new_task_appears_in_the_task_list(): void
    {
        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload(['status' => 'todo']))->assertCreated();

        $this->actingAs($this->manager)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Desain banner promo')
            ->assertJsonPath('meta.status_counts.todo', 1);
    }

    // Validasi

    public function test_title_and_division_are_required(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/tasks', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title' => 'Judul tugas wajib diisi.',
                'division_id' => 'Divisi wajib diisi.',
            ]);
    }

    public function test_unknown_division_category_and_platform_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/tasks', $this->payload([
                'division_id' => 999,
                'category_id' => 999,
                'platform_id' => 999,
                'pic_id' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'division_id' => 'Divisi tidak ditemukan.',
                'category_id' => 'Kategori tidak ditemukan.',
                'platform_id' => 'Platform tidak ditemukan.',
            ]);
    }

    public function test_priority_must_be_one_of_the_allowed_values(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['priority' => 'critical']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['priority' => 'Prioritas harus Low, Medium, High, atau Urgent.']);
    }

    public function test_task_can_only_start_in_backlog_or_todo(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['status' => 'done']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Status awal harus Back Log atau To Do.']);
    }

    public function test_todo_task_requires_a_pic(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['status' => 'todo', 'pic_id' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id' => 'PIC wajib diisi untuk tugas berstatus To Do.']);
    }

    public function test_todo_task_with_a_valid_pic_is_saved_as_todo(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['status' => 'todo']))
            ->assertCreated()
            ->assertJsonPath('data.status', TaskStatus::Todo->value);
    }

    public function test_pic_must_be_staff_of_the_task_division(): void
    {
        foreach ([$this->financeStaff, $this->manager, $this->admin] as $pic) {
            $this->actingAs($this->admin)
                ->postJson('/api/tasks', $this->payload(['pic_id' => $pic->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['pic_id' => 'PIC harus Staff dari divisi yang dipilih.']);
        }

        $this->actingAs($this->admin)
            ->postJson('/api/tasks', $this->payload(['pic_id' => 999]))
            ->assertJsonValidationErrors(['pic_id' => 'PIC harus Staff dari divisi yang dipilih.']);
    }

    public function test_deadline_cannot_be_in_the_past_or_before_the_start_date(): void
    {
        $message = 'Deadline tidak boleh sebelum hari ini atau sebelum tanggal mulai.';

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['deadline' => '2026-10-22']))
            ->assertJsonValidationErrors(['deadline' => $message]);

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['start_date' => '2026-10-28', 'deadline' => '2026-10-27']))
            ->assertJsonValidationErrors(['deadline' => $message]);

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['deadline' => '23/10/2026']))
            ->assertJsonValidationErrors(['deadline' => 'Deadline harus berformat YYYY-MM-DD.']);

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['deadline' => '2026-10-23']))
            ->assertCreated();
    }

    public function test_title_is_limited_to_150_characters(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['title' => str_repeat('a', 151)]))
            ->assertJsonValidationErrors(['title' => 'Judul tugas maksimal 150 karakter.']);
    }

    // Kewajiban QA

    public function test_category_that_requires_qa_always_requires_review(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['category_id' => $this->reviewed->id, 'requires_review' => false]))
            ->assertCreated()
            ->assertJsonPath('data.requires_review', true);

        $this->assertTrue(Task::sole()->requires_review);
    }

    public function test_category_without_qa_follows_the_requested_value(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['category_id' => $this->unreviewed->id]))
            ->assertCreated()
            ->assertJsonPath('data.requires_review', false);

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload([
                'title' => 'Rekap absensi',
                'category_id' => $this->unreviewed->id,
                'requires_review' => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.requires_review', true);
    }

    // Pengiriman berulang

    public function test_identical_resubmission_does_not_create_a_second_task(): void
    {
        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload())->assertCreated();

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload())
            ->assertConflict()
            ->assertJsonPath('message', 'Tugas yang sama baru saja dikirim. Periksa daftar tugas sebelum mengirim ulang.');

        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_same_task_can_be_sent_again_after_the_duplicate_window(): void
    {
        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload())->assertCreated();

        $this->travel(11)->seconds();

        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload())->assertCreated();

        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_rejected_submission_does_not_block_the_corrected_one(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', $this->payload(['status' => 'todo', 'pic_id' => null]))
            ->assertUnprocessable();

        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload(['status' => 'todo']))->assertCreated();
    }

    public function test_different_tasks_sent_back_to_back_are_both_created(): void
    {
        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload())->assertCreated();
        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload(['title' => 'Tugas lain']))->assertCreated();

        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_equivalent_payload_sent_differently_is_still_a_duplicate(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/tasks', [
                'title' => 'Desain banner promo',
                'division_id' => $this->marketing->id,
                'pic_id' => $this->staff->id,
            ])
            ->assertCreated();

        $variants = [
            'urutan key berbeda' => [
                'pic_id' => $this->staff->id,
                'title' => 'Desain banner promo',
                'division_id' => $this->marketing->id,
            ],
            'id sebagai string' => [
                'title' => 'Desain banner promo',
                'division_id' => (string) $this->marketing->id,
                'pic_id' => (string) $this->staff->id,
            ],
            'default dikirim eksplisit' => [
                'title' => 'Desain banner promo',
                'division_id' => $this->marketing->id,
                'pic_id' => $this->staff->id,
                'priority' => 'medium',
                'status' => 'backlog',
                'requires_review' => false,
            ],
            'field opsional dikirim null' => [
                'title' => 'Desain banner promo',
                'division_id' => $this->marketing->id,
                'pic_id' => $this->staff->id,
                'description' => null,
                'category_id' => null,
                'deadline' => null,
            ],
        ];

        foreach ($variants as $variant => $payload) {
            $this->actingAs($this->manager)
                ->postJson('/api/tasks', $payload)
                ->assertConflict();
        }

        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_identical_request_arriving_while_the_first_is_saving_is_rejected(): void
    {
        $service = app(TaskService::class);
        $data = ['title' => 'Desain banner promo', 'division_id' => $this->marketing->id, 'pic_id' => $this->staff->id];
        $secondRejected = false;

        // The second request arrives after the first took the guard but before its row is stored.
        Task::creating(function () use ($service, $data, &$secondRejected) {
            Task::flushEventListeners();

            try {
                $service->create($this->manager, $data);
            } catch (DuplicateTaskSubmissionException) {
                $secondRejected = true;
            }
        });

        $service->create($this->manager, $data);

        $this->assertTrue($secondRejected);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_same_task_from_two_accounts_is_not_a_duplicate(): void
    {
        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload())->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/tasks', $this->payload())->assertCreated();

        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_failed_save_releases_the_duplicate_guard(): void
    {
        Task::creating(fn () => throw new \RuntimeException('Database down'));

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload());
            $this->fail('The save should have failed.');
        } catch (\RuntimeException) {
        }

        Task::flushEventListeners();

        $this->actingAs($this->manager)->postJson('/api/tasks', $this->payload())->assertCreated();
    }

    // Pilihan form

    public function test_admin_create_options_list_every_division_with_its_staff(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/tasks/create-options')
            ->assertOk()
            ->assertJsonPath('data.divisions.0.code_division', 'FIN')
            ->assertJsonPath('data.divisions.0.pics', [['id' => $this->financeStaff->id, 'fullname' => 'fin.staff']])
            ->assertJsonPath('data.divisions.1.code_division', 'MKT')
            ->assertJsonPath('data.divisions.1.pics', [['id' => $this->staff->id, 'fullname' => 'mkt.staff']])
            ->assertJsonPath('data.statuses', [
                ['value' => 'backlog', 'label' => 'Back Log'],
                ['value' => 'todo', 'label' => 'To Do'],
            ])
            ->assertJsonPath('data.defaults', ['priority' => 'medium', 'status' => 'backlog'])
            ->assertJsonCount(4, 'data.priorities')
            ->assertJsonFragment(['name' => 'Konten', 'requires_review' => true]);
    }

    public function test_manager_create_options_only_list_their_division(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/tasks/create-options')
            ->assertOk()
            ->assertJsonCount(1, 'data.divisions')
            ->assertJsonPath('data.divisions.0.code_division', 'MKT');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Desain banner promo',
            'division_id' => $this->marketing->id,
            'pic_id' => $this->staff->id,
            ...$overrides,
        ];
    }

    private function account(string $role, string $username, ?Division $division): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $division?->id,
            'fullname' => $username,
            'username' => $username,
            'password' => 'Password123!',
        ]);
    }
}
