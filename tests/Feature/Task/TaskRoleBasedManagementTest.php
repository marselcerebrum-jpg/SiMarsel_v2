<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAssignment;
use App\Models\TaskEvidence;
use App\Services\TaskEvidenceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-13 (STEAM-245): the task's main instruction stays out of a PIC's reach, deleting one's own evidence, PICs a
 * Supervisor may add, and who moves a task to another division.
 */
class TaskRoleBasedManagementTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    /**
     * @var array<string, Account>
     */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-11-18 09:00:00');
        Storage::fake('local');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->accounts = [
            'admin' => $this->account(Role::ADMIN, 'admin', null),
            'manager' => $this->account(Role::MANAGER, 'manager', $this->finance),
            'supervisor' => $this->account(Role::SUPERVISOR, 'supervisor', $this->marketing),
            'staff' => $this->account(Role::EMPLOYEE, 'staff', $this->marketing),
            'other' => $this->account(Role::EMPLOYEE, 'other', $this->marketing),
            'freelance' => $this->account(Role::FREELANCE, 'freelance', null),
            'finstaff' => $this->account(Role::EMPLOYEE, 'finstaff', $this->finance),
        ];
    }

    // Instruksi utama tugas

    public function test_a_staff_or_freelance_pic_cannot_change_the_main_instruction(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress, 'description' => 'Instruksi utama.'], ['staff', 'freelance']);

        foreach (['staff', 'freelance'] as $role) {
            $this->actingAs($this->accounts[$role])
                ->patchJson("/api/tasks/{$task->id}", ['description' => 'Diubah PIC.', 'version' => 1])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['description' => 'PIC hanya dapat mengubah status tugas.']);
        }

        $this->assertSame('Instruksi utama.', $task->fresh()->description);
    }

    // Hapus evidence milik sendiri

    public function test_the_uploader_deletes_their_own_evidence_without_touching_the_task(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress, 'evidence_required' => true], ['staff', 'other']);
        $file = $this->upload($task, 'staff');
        $kept = $this->link($task, 'other');

        $this->actingAs($this->accounts['staff'])
            ->getJson("/api/tasks/{$task->id}/evidences")
            ->assertJsonPath('data.0.id', $kept->id)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonPath('data.1.can_delete', true);

        $this->deleteEvidence($task, $file, 'staff')
            ->assertOk()
            ->assertJsonPath('message', 'Evidence berhasil dihapus.');

        $deleted = TaskEvidence::withTrashed()->find($file->id);
        $this->assertSoftDeleted($deleted);
        $this->assertSame($this->accounts['staff']->id, $deleted->deleted_by);
        Storage::disk('local')->assertExists($deleted->file_path);

        $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}/evidences")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kept->id);
        $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}")
            ->assertJsonCount(1, 'data.evidence')
            ->assertJsonPath('data.evidence_count', 1)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.version', 1);
        $this->actingAs($this->accounts['staff'])->get("/api/tasks/{$task->id}/evidences/{$file->id}/download")->assertNotFound();
        $this->assertNotSoftDeleted($task);

        $activity = $task->activities()->first();
        $this->assertSame(TaskActivity::EVIDENCE_DELETED, $activity->action);
        $this->assertSame($this->accounts['staff']->id, $activity->account_id);
        $this->assertEquals(['id' => $file->id, 'type' => 'file', 'name' => 'hasil.pdf'], $activity->changes['evidence']['old']);
        $this->assertSame('Evidence: hasil.pdf', $activity->description());
    }

    public function test_nobody_deletes_evidence_uploaded_by_someone_else(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other']);
        $evidence = $this->link($task, 'staff');
        $legacy = TaskEvidence::create(['task_id' => $task->id, 'submitter_id' => null, 'type' => 'link', 'url' => 'https://example.com/lama']);

        foreach (['other', 'admin', 'manager', 'supervisor', 'freelance', 'finstaff'] as $role) {
            $this->deleteEvidence($task, $evidence, $role)->assertForbidden();
        }

        $this->deleteEvidence($task, $legacy, 'staff')->assertForbidden();
        $this->deleteEvidence($task, $legacy, 'other')->assertForbidden();

        $this->assertNotSoftDeleted($evidence);
        $this->assertNotSoftDeleted($legacy);
        $this->assertSame(0, $task->activities()->where('action', TaskActivity::EVIDENCE_DELETED)->count());
    }

    public function test_a_released_uploader_can_no_longer_delete_their_evidence(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other']);
        $evidence = $this->link($task, 'staff');

        $this->actingAs($this->accounts['admin'])
            ->patchJson("/api/tasks/{$task->id}/assignment", ['pic_ids' => [$this->accounts['other']->id], 'version' => 1])
            ->assertOk();

        $this->deleteEvidence($task, $evidence, 'staff')->assertForbidden();
        $this->assertNotSoftDeleted($evidence);
    }

    public function test_evidence_under_review_or_of_a_finished_task_cannot_be_deleted(): void
    {
        foreach ([TaskStatus::ReadyReview, TaskStatus::OnReview, TaskStatus::Done] as $status) {
            $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
            $evidence = $this->link($task, 'staff');
            $task->update(['status' => $status]);

            $this->deleteEvidence($task, $evidence, 'staff')
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['evidence' => "Evidence hanya dapat dihapus saat tugas berstatus Back Log, To Do, atau On Process, bukan {$status->label()}."]);
            $this->assertNotSoftDeleted($evidence);
        }

        $todo = $this->task(['status' => TaskStatus::Todo], ['staff']);
        $this->deleteEvidence($todo, $this->link($todo, 'staff'), 'staff')->assertOk();
    }

    public function test_evidence_of_another_task_or_already_deleted_is_not_found(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $other = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $evidence = $this->link($other, 'staff');

        $this->deleteEvidence($task, $evidence, 'staff')->assertNotFound();
        $this->assertNotSoftDeleted($evidence);

        $this->deleteEvidence($other, $evidence, 'staff')->assertOk();
        $this->deleteEvidence($other, $evidence, 'staff')->assertNotFound();
    }

    public function test_a_second_request_for_the_same_evidence_stops_without_adding_history(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $evidence = $this->link($task, 'staff');
        $service = app(TaskEvidenceService::class);

        // Both requests resolved the evidence before either of them got its turn on the task lock.
        $first = TaskEvidence::findOrFail($evidence->id);
        $second = TaskEvidence::findOrFail($evidence->id);

        $service->delete($task, $first, $this->accounts['staff']);
        $deletedAt = TaskEvidence::withTrashed()->findOrFail($evidence->id)->deleted_at;
        $this->travel(5)->minutes();

        try {
            $service->delete($task, $second, $this->accounts['staff']);
            $this->fail('The second request should find the evidence already deleted.');
        } catch (ModelNotFoundException) {
            $this->assertSame(1, $task->activities()->where('action', TaskActivity::EVIDENCE_DELETED)->count());
            $this->assertTrue($deletedAt->equalTo(TaskEvidence::withTrashed()->findOrFail($evidence->id)->deleted_at));
        }
    }

    // Cakupan PIC Supervisor

    public function test_a_supervisor_picks_pics_only_from_their_division_while_admin_and_managers_pick_from_every_division(): void
    {
        $names = fn (string $role) => collect($this->actingAs($this->accounts[$role])->getJson('/api/tasks/create-options')->assertOk()->json('data.pics'))
            ->pluck('fullname')->sort()->values()->all();

        $everyone = ['finstaff', 'freelance', 'other', 'staff', 'supervisor'];
        $this->assertSame($everyone, $names('admin'));
        $this->assertSame($everyone, $names('manager'));
        $this->assertSame(['other', 'staff', 'supervisor'], $names('supervisor'));

        $this->actingAs($this->accounts['supervisor'])
            ->postJson('/api/tasks', ['title' => 'Lintas divisi', 'division_id' => $this->marketing->id, 'pic_ids' => [$this->accounts['staff']->id, $this->accounts['finstaff']->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids.1' => 'Supervisor hanya dapat menugaskan PIC dari divisinya sendiri.']);

        $this->actingAs($this->accounts['supervisor'])
            ->postJson('/api/tasks', ['title' => 'Divisi sendiri', 'division_id' => $this->marketing->id, 'status' => 'todo', 'pic_ids' => [$this->accounts['staff']->id, $this->accounts['supervisor']->id]])
            ->assertCreated();

        $this->actingAs($this->accounts['manager'])
            ->postJson('/api/tasks', ['title' => 'Manajer lintas divisi', 'division_id' => $this->marketing->id, 'status' => 'todo', 'pic_ids' => [$this->accounts['staff']->id, $this->accounts['finstaff']->id, $this->accounts['freelance']->id]])
            ->assertCreated();
    }

    public function test_a_supervisor_keeps_or_releases_a_pic_of_another_division_but_cannot_add_one(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff', 'finstaff']);
        $staff = $this->accounts['staff']->id;
        $finstaff = $this->accounts['finstaff']->id;
        $other = $this->accounts['other']->id;
        $freelance = $this->accounts['freelance']->id;

        $this->assign($task, 'supervisor', [$staff, $finstaff, $other])->assertOk();
        $this->assign($task, 'supervisor', [$staff, $other, $freelance])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids.2' => 'Supervisor hanya dapat menugaskan PIC dari divisinya sendiri.']);
        $this->assign($task, 'supervisor', [$staff, $other])->assertOk();
        $this->assign($task, 'supervisor', [$staff, $other, $finstaff])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids.2']);

        $this->assertEqualsCanonicalizing([$staff, $other], $task->fresh()->activeAssignments()->pluck('account_id')->all());

        $options = $this->actingAs($this->accounts['supervisor'])->getJson("/api/tasks/{$task->id}/assignment-options")->assertOk();
        $options->assertJsonPath('data.can_change_division', false)
            ->assertJsonPath('data.divisions', [['id' => $this->marketing->id, 'code_division' => 'MKT', 'name' => 'Marketing']]);
        $this->assertSame(['other', 'staff', 'supervisor'], collect($options->json('data.pics'))->pluck('fullname')->sort()->values()->all());

        $this->actingAs($this->accounts['manager'])->getJson("/api/tasks/{$task->id}/assignment-options")
            ->assertJsonPath('data.can_change_division', true)
            ->assertJsonCount(2, 'data.divisions')
            ->assertJsonCount(5, 'data.pics');
    }

    public function test_only_admin_and_managers_move_a_task_to_another_division_through_the_edit_form(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff']);

        $this->actingAs($this->accounts['supervisor'])->getJson("/api/tasks/{$task->id}")
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.editable_fields', fn (array $fields) => ! in_array('division_id', $fields, true) && in_array('title', $fields, true));
        $this->actingAs($this->accounts['supervisor'])
            ->patchJson("/api/tasks/{$task->id}", ['division_id' => $this->finance->id, 'version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division_id' => 'Hanya Admin dan Manajer yang dapat memindahkan tugas ke divisi lain.']);
        $this->assertSame($this->marketing->id, $task->fresh()->division_id);

        foreach (['manager', 'admin'] as $role) {
            $this->actingAs($this->accounts[$role])->getJson("/api/tasks/{$task->id}")
                ->assertJsonPath('data.editable_fields', fn (array $fields) => in_array('division_id', $fields, true));
        }

        $this->actingAs($this->accounts['manager'])
            ->patchJson("/api/tasks/{$task->id}", ['division_id' => $this->finance->id, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.division.code_division', 'FIN');
    }

    public function test_changing_pics_does_not_change_the_status_or_the_other_pics(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other']);
        $kept = $this->assignmentOf($task, 'other');

        $this->assign($task, 'supervisor', [$this->accounts['other']->id, $this->accounts['supervisor']->id])->assertOk();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::InProgress, $fresh->status);
        $this->assertTrue($kept->is($this->assignmentOf($fresh, 'other')));
        $this->assertSame($kept->assigned_at->toIso8601String(), $this->assignmentOf($fresh, 'other')->assigned_at->toIso8601String());

        $activity = $fresh->activities()->first();
        $this->assertSame(TaskActivity::REASSIGNED, $activity->action);
        $this->assertSame($this->accounts['supervisor']->id, $activity->account_id);
        $this->assertSame(['other', 'staff'], array_column($activity->changes['pics']['old'], 'name'));
        $this->assertSame(['other', 'supervisor'], array_column($activity->changes['pics']['new'], 'name'));
    }

    /**
     * @param  array<int, int>  $picIds
     */
    private function assign(Task $task, string $role, array $picIds): TestResponse
    {
        return $this->actingAs($this->accounts[$role])
            ->patchJson("/api/tasks/{$task->id}/assignment", ['pic_ids' => $picIds, 'version' => $task->fresh()->version]);
    }

    private function deleteEvidence(Task $task, TaskEvidence $evidence, string $role): TestResponse
    {
        return $this->actingAs($this->accounts[$role])->deleteJson("/api/tasks/{$task->id}/evidences/{$evidence->id}");
    }

    private function upload(Task $task, string $role): TaskEvidence
    {
        $this->actingAs($this->accounts[$role])
            ->post("/api/tasks/{$task->id}/evidences", ['file' => UploadedFile::fake()->create('hasil.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated();

        return TaskEvidence::where('task_id', $task->id)->latest('id')->firstOrFail();
    }

    private function link(Task $task, string $role): TaskEvidence
    {
        $this->travel(1)->seconds();

        return TaskEvidence::create([
            'task_id' => $task->id,
            'submitter_id' => $this->accounts[$role]->id,
            'type' => 'link',
            'url' => "https://example.com/{$role}",
        ]);
    }

    private function assignmentOf(Task $task, string $role): TaskAssignment
    {
        return $task->activeAssignments()->where('account_id', $this->accounts[$role]->id)->sole();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $pics
     */
    private function task(array $attributes = [], array $pics = []): Task
    {
        return Task::factory()
            ->withPics(...array_map(fn (string $key) => $this->accounts[$key], $pics))
            ->create([
                'division_id' => $this->marketing->id,
                'creator_id' => $this->accounts['admin']->id,
                'status' => TaskStatus::Backlog,
                'requires_review' => true,
                'evidence_required' => false,
                'start_date' => null,
                'deadline' => null,
                ...$attributes,
            ]);
    }

    private function account(string $role, string $username, ?Division $division): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $division?->id,
            'fullname' => $username,
            'username' => "t13.{$username}",
            'password' => 'Password123!',
        ]);
    }
}
