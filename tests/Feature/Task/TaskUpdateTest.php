<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskCategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $financeManager;

    private Account $staff;

    private Account $otherStaff;

    private Account $financeStaff;

    private TaskCategory $reviewed;

    private TaskCategory $unreviewed;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-10-23 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->financeManager = $this->account(Role::MANAGER, 'fin.manager', $this->finance);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);
        $this->financeStaff = $this->account(Role::EMPLOYEE, 'fin.staff', $this->finance);

        $this->reviewed = TaskCategory::where('name', 'Konten')->firstOrFail();
        $this->unreviewed = TaskCategory::where('name', 'Administrasi')->firstOrFail();

        $this->task = $this->task();
    }

    // Akses

    public function test_guest_cannot_update_a_task(): void
    {
        $this->patchJson($this->url(), $this->payload(['title' => 'Baru']))->assertUnauthorized();
    }

    public function test_staff_cannot_update_even_their_own_task(): void
    {
        $this->actingAs($this->staff)
            ->patchJson($this->url(), $this->payload(['title' => 'Baru']))
            ->assertForbidden();

        $this->assertSame('Desain banner promo', $this->task->fresh()->title);
    }

    public function test_manager_cannot_update_a_task_of_another_division(): void
    {
        $this->actingAs($this->financeManager)
            ->patchJson($this->url(), $this->payload(['title' => 'Baru']))
            ->assertForbidden();
    }

    public function test_updating_an_unknown_task_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/tasks/999999', $this->payload(['title' => 'Baru']))
            ->assertNotFound();
    }

    public function test_manager_can_update_a_task_of_their_division(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([
                'title' => 'Desain banner promo Desember',
                'description' => 'Revisi warna.',
                'priority' => 'urgent',
                'pic_id' => $this->otherStaff->id,
                'start_date' => '2026-10-24',
                'deadline' => '2026-10-31',
            ]))
            ->assertOk()
            ->assertJsonPath('message', 'Tugas berhasil diperbarui.')
            ->assertJsonPath('data.title', 'Desain banner promo Desember')
            ->assertJsonPath('data.description', 'Revisi warna.')
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.pic.id', $this->otherStaff->id)
            ->assertJsonPath('data.deadline', '2026-10-31')
            ->assertJsonPath('data.version', 2);
    }

    public function test_only_sent_fields_are_changed(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['priority' => 'low']))
            ->assertOk();

        $task = $this->task->fresh();
        $this->assertSame('low', $task->priority->value);
        $this->assertSame('Desain banner promo', $task->title);
        $this->assertSame($this->staff->id, $task->pic_id);
    }

    public function test_updated_task_appears_in_the_task_list(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload(['title' => 'Judul baru', 'status' => 'in_progress']))
            ->assertOk();

        $this->getJson('/api/tasks')
            ->assertJsonPath('data.0.title', 'Judul baru')
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('meta.status_counts.in_progress', 1);
    }

    // Validasi field

    public function test_version_is_required(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), ['title' => 'Baru'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version' => 'Versi tugas wajib dikirim. Muat ulang detail tugas.']);
    }

    public function test_field_values_are_validated(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload([
                'title' => '',
                'priority' => 'critical',
                'status' => 'archived',
                'category_id' => 999,
                'deadline' => '23-10-2026',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'priority', 'status', 'category_id', 'deadline']);
    }

    public function test_new_deadline_cannot_be_in_the_past_or_before_the_start_date(): void
    {
        $this->actingAs($this->admin);

        $this->patchJson($this->url(), $this->payload(['deadline' => '2026-10-22']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deadline' => 'Deadline tidak boleh sebelum hari ini.']);

        $this->patchJson($this->url(), $this->payload(['start_date' => '2026-11-05']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deadline' => 'Deadline tidak boleh sebelum tanggal mulai.']);
    }

    public function test_a_past_deadline_that_is_not_changed_does_not_block_the_edit(): void
    {
        $this->task->forceFill(['deadline' => '2026-10-01', 'start_date' => '2026-09-25'])->save();

        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['title' => 'Baru', 'deadline' => '2026-10-01']))
            ->assertOk();
    }

    // PIC & divisi

    public function test_pic_must_be_staff_of_the_task_division(): void
    {
        $this->actingAs($this->admin);

        foreach ([$this->financeStaff, $this->manager] as $pic) {
            $this->patchJson($this->url(), $this->payload(['pic_id' => $pic->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['pic_id' => 'PIC harus Staff dari divisi yang dipilih.']);
        }
    }

    public function test_pic_is_required_once_the_task_leaves_back_log(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['pic_id' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id' => 'PIC wajib diisi untuk tugas yang tidak berstatus Back Log.']);

        $this->patchJson($this->url(), $this->payload(['status' => 'backlog', 'pic_id' => null]))
            ->assertOk()
            ->assertJsonPath('data.pic', null);
    }

    public function test_admin_can_move_a_task_to_another_division_with_a_pic_from_it(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload([
                'division_id' => $this->finance->id,
                'pic_id' => $this->financeStaff->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.division.code_division', 'FIN')
            ->assertJsonPath('data.pic.id', $this->financeStaff->id);
    }

    public function test_moving_division_keeps_the_pic_only_when_they_belong_to_it(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['division_id' => $this->finance->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_id' => 'PIC saat ini bukan Staff dari divisi yang dipilih. Pilih PIC baru.']);
    }

    public function test_manager_cannot_move_a_task_to_another_division(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([
                'division_id' => $this->finance->id,
                'pic_id' => $this->financeStaff->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division_id' => 'Hanya Admin yang dapat memindahkan tugas ke divisi lain.']);

        $this->assertSame($this->marketing->id, $this->task->fresh()->division_id);
    }

    public function test_resending_unchanged_values_is_allowed(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([
                'title' => 'Desain banner promo',
                'division_id' => $this->marketing->id,
                'pic_id' => $this->staff->id,
                'status' => 'todo',
            ]))
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseCount('task_activities', 0);
    }

    // Workflow status

    public function test_status_follows_the_workflow(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['status' => 'on_qa']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Status tidak dapat diubah dari To Do ke On QA.']);

        $this->patchJson($this->url(), $this->payload(['status' => 'in_progress']))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_task_that_requires_qa_cannot_skip_qa(): void
    {
        $this->task->forceFill(['status' => TaskStatus::InProgress])->save();

        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['status' => 'done']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'status' => 'Status tidak dapat diubah dari On Process ke Done. Tugas ini wajib melalui QA sebelum Done.',
            ]);
    }

    public function test_task_without_qa_goes_straight_to_done_and_records_completion(): void
    {
        $task = $this->task(['category_id' => $this->unreviewed->id, 'requires_review' => false, 'status' => TaskStatus::InProgress]);

        $this->actingAs($this->manager)
            ->patchJson("/api/tasks/{$task->id}", ['version' => 1, 'status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_at', now()->toIso8601ZuluString());

        $this->patchJson("/api/tasks/{$task->id}", ['version' => 2, 'status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_done_task_can_only_be_reopened(): void
    {
        $this->task->forceFill(['status' => TaskStatus::Done, 'completed_at' => now()])->save();

        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['title' => 'Baru']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'Tugas yang sudah selesai tidak dapat diubah. Buka kembali tugas terlebih dahulu.']);

        $this->getJson($this->url())->assertJsonPath('data.editable_fields', ['status']);

        $this->patchJson($this->url(), $this->payload(['status' => 'in_progress']))->assertOk();
    }

    // QA

    public function test_qa_cannot_be_turned_off_for_a_category_that_requires_it(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['requires_review' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['requires_review' => 'Kategori tugas ini mewajibkan QA.']);
    }

    public function test_choosing_a_category_that_requires_qa_turns_qa_on(): void
    {
        $task = $this->task(['category_id' => $this->unreviewed->id, 'requires_review' => false]);

        $this->actingAs($this->admin)
            ->patchJson("/api/tasks/{$task->id}", ['version' => 1, 'category_id' => $this->reviewed->id])
            ->assertOk()
            ->assertJsonPath('data.requires_review', true);
    }

    public function test_qa_can_be_changed_for_a_category_without_qa(): void
    {
        $task = $this->task(['category_id' => $this->unreviewed->id, 'requires_review' => false]);

        $this->actingAs($this->admin)
            ->patchJson("/api/tasks/{$task->id}", ['version' => 1, 'requires_review' => true])
            ->assertOk()
            ->assertJsonPath('data.requires_review', true);
    }

    public function test_qa_requirement_is_locked_once_the_task_is_in_qa(): void
    {
        $task = $this->task([
            'category_id' => $this->unreviewed->id,
            'requires_review' => true,
            'status' => TaskStatus::ReadyForQa,
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/tasks/{$task->id}", ['version' => 1, 'requires_review' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['requires_review' => 'Kewajiban QA tidak dapat diubah setelah tugas masuk tahap QA.']);
    }

    // Histori

    public function test_changes_are_recorded_in_the_history(): void
    {
        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload([
                'title' => 'Judul baru',
                'pic_id' => $this->otherStaff->id,
                'status' => 'in_progress',
                'deadline' => '2026-11-01',
            ]))
            ->assertOk()
            ->assertJsonPath('data.history.0.action', 'updated')
            ->assertJsonPath('data.history.0.actor.id', $this->manager->id)
            ->assertJsonPath('data.history.0.changes.title', ['old' => 'Desain banner promo', 'new' => 'Judul baru'])
            ->assertJsonPath('data.history.0.changes.pic', [
                'old' => ['id' => $this->staff->id, 'name' => 'mkt.staff'],
                'new' => ['id' => $this->otherStaff->id, 'name' => 'mkt.other'],
            ])
            ->assertJsonPath('data.history.0.changes.status', ['old' => 'todo', 'new' => 'in_progress'])
            ->assertJsonPath('data.history.0.changes.deadline', ['old' => '2026-10-30', 'new' => '2026-11-01']);
    }

    public function test_creating_a_task_records_it_in_the_history(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson('/api/tasks', ['title' => 'Tugas baru', 'division_id' => $this->marketing->id])
            ->assertCreated();

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $response->json('data.id'),
            'account_id' => $this->manager->id,
            'action' => TaskActivity::CREATED,
        ]);
    }

    // Konflik data lama

    public function test_an_edit_based_on_an_old_version_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['title' => 'Edit pertama']))
            ->assertOk();

        $this->actingAs($this->manager)
            ->patchJson($this->url(), $this->payload(['title' => 'Edit kedua']))
            ->assertConflict()
            ->assertJsonPath('message', 'Tugas telah diubah oleh pengguna lain. Muat ulang detail tugas sebelum menyimpan perubahan.')
            ->assertJsonPath('current_version', 2);

        $this->assertSame('Edit pertama', $this->task->fresh()->title);
        $this->assertDatabaseCount('task_activities', 1);
    }

    public function test_an_edit_based_on_the_latest_version_is_saved(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->url(), $this->payload(['title' => 'Edit pertama']))
            ->assertOk();

        $this->patchJson($this->url(), ['version' => 2, 'title' => 'Edit kedua'])
            ->assertOk()
            ->assertJsonPath('data.version', 3);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['version' => 1, ...$overrides];
    }

    private function url(): string
    {
        return "/api/tasks/{$this->task->id}";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): Task
    {
        return Task::factory()->create([
            'title' => 'Desain banner promo',
            'category_id' => $this->reviewed->id,
            'division_id' => $this->marketing->id,
            'pic_id' => $this->staff->id,
            'creator_id' => $this->manager->id,
            'status' => TaskStatus::Todo,
            'start_date' => '2026-10-24',
            'deadline' => '2026-10-30',
            'requires_review' => true,
            ...$attributes,
        ]);
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
