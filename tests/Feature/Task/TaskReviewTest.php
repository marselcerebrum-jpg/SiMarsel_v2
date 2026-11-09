<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskReviewTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $financeManager;

    private Account $staff;

    private Account $otherStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-11-05 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->financeManager = $this->account(Role::MANAGER, 'fin.manager', $this->finance);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);
        $this->otherStaff = $this->account(Role::EMPLOYEE, 'mkt.other', $this->marketing);
    }

    // Akses

    public function test_guest_cannot_review(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->postJson($this->url($task), $this->approve())->assertUnauthorized();
        $this->getJson($this->url($task))->assertUnauthorized();
    }

    public function test_staff_cannot_approve_or_request_revision_even_on_their_own_task(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->staff)->postJson($this->url($task), $this->approve())->assertForbidden();
        $this->actingAs($this->staff)->postJson($this->url($task), $this->revision())->assertForbidden();

        $this->assertSame(TaskStatus::OnQa, $task->fresh()->status);
        $this->assertDatabaseCount('task_reviews', 0);
    }

    public function test_manager_cannot_review_a_task_of_another_division(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->financeManager)->postJson($this->url($task), $this->approve())->assertForbidden();

        $this->assertSame(TaskStatus::OnQa, $task->fresh()->status);
    }

    public function test_admin_reviews_tasks_of_any_division(): void
    {
        $task = $this->task(TaskStatus::OnQa, ['division_id' => $this->finance->id, 'pic_id' => null]);

        $this->actingAs($this->admin)->postJson($this->url($task), $this->approve())->assertOk();
    }

    public function test_unknown_task_returns_not_found(): void
    {
        $this->actingAs($this->admin)->postJson('/api/tasks/999999/reviews', $this->approve())->assertNotFound();
    }

    // Approve

    public function test_manager_approves_a_task_in_qa(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->manager)
            ->postJson($this->url($task), $this->approve())
            ->assertOk()
            ->assertJsonPath('message', 'Tugas berhasil disetujui.')
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.completed_at', now()->toIso8601ZuluString())
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.last_review.decision', 'approve')
            ->assertJsonPath('data.last_review.cycle', 1)
            ->assertJsonPath('data.last_review.reviewer', ['id' => $this->manager->id, 'fullname' => 'mkt.manager']);

        $this->assertDatabaseHas('task_reviews', [
            'task_id' => $task->id,
            'reviewer_id' => $this->manager->id,
            'decision' => 'approve',
            'note' => null,
        ]);
    }

    public function test_approval_may_carry_a_note(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->manager)
            ->postJson($this->url($task), $this->approve(['note' => 'Rapi.']))
            ->assertOk()
            ->assertJsonPath('data.last_review.note', 'Rapi.');
    }

    public function test_decision_is_only_given_on_a_task_in_qa(): void
    {
        foreach ([TaskStatus::InProgress, TaskStatus::ReadyForQa, TaskStatus::Done] as $status) {
            $task = $this->task($status);

            $this->actingAs($this->manager)
                ->postJson($this->url($task), $this->approve())
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'decision' => "Keputusan review hanya dapat diberikan pada tugas berstatus On QA, bukan {$status->label()}.",
                ]);

            $this->assertSame($status, $task->fresh()->status);
        }

        $this->assertDatabaseCount('task_reviews', 0);
    }

    public function test_task_without_qa_cannot_be_reviewed(): void
    {
        $task = $this->task(TaskStatus::InProgress, ['requires_review' => false]);

        $this->actingAs($this->manager)->postJson($this->url($task), $this->approve())->assertForbidden();
    }

    // Request Revision

    public function test_revision_requires_a_reason(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        foreach ([[], ['note' => ''], ['note' => '   ']] as $note) {
            $this->actingAs($this->manager)
                ->postJson($this->url($task), ['version' => 1, 'decision' => 'revision', ...$note])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['note' => 'Catatan alasan revisi wajib diisi.']);
        }

        $this->assertSame(TaskStatus::OnQa, $task->fresh()->status);
        $this->assertDatabaseCount('task_reviews', 0);
    }

    public function test_decision_and_version_are_validated(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->manager)
            ->postJson($this->url($task), ['decision' => 'reject', 'note' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'version' => 'Versi tugas wajib dikirim. Muat ulang detail tugas.',
                'decision' => 'Keputusan review tidak valid.',
                'note' => 'Catatan review maksimal 2000 karakter.',
            ]);
    }

    public function test_revision_sends_the_task_back_to_its_pic_with_the_reason(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->manager)
            ->postJson($this->url($task), $this->revision())
            ->assertOk()
            ->assertJsonPath('message', 'Permintaan revisi berhasil dikirim.')
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.last_review.decision', 'revision')
            ->assertJsonPath('data.last_review.note', 'Lengkapi data bulan Oktober.');

        $this->actingAs($this->staff)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.next_statuses', [['value' => 'ready_for_qa', 'label' => 'Ready for QA']])
            ->assertJsonPath('data.last_review.note', 'Lengkapi data bulan Oktober.')
            ->assertJsonPath('data.can_review', false);
    }

    // Siklus revisi & histori

    public function test_revision_cycle_repeats_until_approved_and_keeps_every_review(): void
    {
        $task = $this->task(TaskStatus::ReadyForQa);

        $this->moveTo($task, $this->manager, 'on_qa');
        $this->decide($task, $this->manager, $this->revision(['note' => 'Revisi pertama.']));
        $this->moveTo($task, $this->staff, 'ready_for_qa');
        $this->moveTo($task, $this->manager, 'on_qa');
        $this->decide($task, $this->admin, $this->revision(['note' => 'Revisi kedua.']));
        $this->moveTo($task, $this->staff, 'ready_for_qa');
        $this->moveTo($task, $this->manager, 'on_qa');
        $this->decide($task, $this->manager, $this->approve());

        $this->actingAs($this->staff)
            ->getJson($this->url($task))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.cycle', 3)
            ->assertJsonPath('data.0.decision', 'approve')
            ->assertJsonPath('data.1.cycle', 2)
            ->assertJsonPath('data.1.note', 'Revisi kedua.')
            ->assertJsonPath('data.1.reviewer.id', $this->admin->id)
            ->assertJsonPath('data.2.cycle', 1)
            ->assertJsonPath('data.2.note', 'Revisi pertama.');

        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
    }

    public function test_review_decisions_and_resubmission_are_recorded_in_history(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->decide($task, $this->manager, $this->revision());
        $this->moveTo($task, $this->staff, 'ready_for_qa');

        $activities = $task->activities()->get();

        $this->assertSame(
            [TaskActivity::RESUBMITTED, TaskActivity::REVISION_REQUESTED],
            $activities->pluck('action')->all(),
        );
        $this->assertSame(['old' => 'on_qa', 'new' => 'in_progress'], $activities[1]->changes['status']);
        $this->assertSame('Lengkapi data bulan Oktober.', $activities[1]->changes['review']['new']['note']);
        $this->assertSame($this->manager->id, $activities[1]->account_id);
    }

    public function test_first_submission_to_qa_is_a_plain_status_change(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->moveTo($task, $this->staff, 'ready_for_qa');

        $this->assertSame(TaskActivity::STATUS_CHANGED, $task->activities()->value('action'));
    }

    public function test_decision_based_on_an_outdated_version_is_rejected(): void
    {
        $task = $this->task(TaskStatus::OnQa);
        $task->forceFill(['version' => 3])->save();

        $this->actingAs($this->manager)
            ->postJson($this->url($task), $this->approve(['version' => 2]))
            ->assertConflict()
            ->assertJsonPath('current_version', 3);

        $this->assertSame(TaskStatus::OnQa, $task->fresh()->status);
        $this->assertDatabaseCount('task_reviews', 0);
    }

    // Melihat hasil review

    public function test_pic_sees_the_review_results_of_their_task(): void
    {
        $task = $this->task(TaskStatus::OnQa);
        $this->decide($task, $this->manager, $this->revision());

        $this->actingAs($this->staff)
            ->getJson($this->url($task))
            ->assertOk()
            ->assertJsonPath('data.0.decision', 'revision')
            ->assertJsonPath('data.0.decision_label', 'Request Revision')
            ->assertJsonPath('data.0.note', 'Lengkapi data bulan Oktober.');
    }

    public function test_other_staff_and_other_division_managers_cannot_see_the_reviews(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->otherStaff)->getJson($this->url($task))->assertForbidden();
        $this->actingAs($this->financeManager)->getJson($this->url($task))->assertForbidden();
    }

    public function test_detail_tells_the_reviewer_when_a_decision_is_awaited(): void
    {
        $task = $this->task(TaskStatus::OnQa);

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$task->id}")
            ->assertJsonPath('data.can_review', true)
            ->assertJsonPath('data.reviews', [])
            ->assertJsonPath('data.last_review', null);

        $this->actingAs($this->staff)
            ->getJson("/api/tasks/{$task->id}")
            ->assertJsonPath('data.can_review', false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decide(Task $task, Account $reviewer, array $data): void
    {
        $this->actingAs($reviewer)
            ->postJson($this->url($task), ['version' => $task->fresh()->version, ...array_diff_key($data, ['version' => 0])])
            ->assertOk();
    }

    private function moveTo(Task $task, Account $actor, string $status): void
    {
        $this->actingAs($actor)
            ->patchJson("/api/tasks/{$task->id}/status", ['version' => $task->fresh()->version, 'status' => $status])
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function approve(array $overrides = []): array
    {
        return ['version' => 1, 'decision' => 'approve', ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function revision(array $overrides = []): array
    {
        return ['version' => 1, 'decision' => 'revision', 'note' => 'Lengkapi data bulan Oktober.', ...$overrides];
    }

    private function url(Task $task): string
    {
        return "/api/tasks/{$task->id}/reviews";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(TaskStatus $status, array $attributes = []): Task
    {
        return Task::factory()->create([
            'division_id' => $this->marketing->id,
            'pic_id' => $this->staff->id,
            'creator_id' => $this->manager->id,
            'status' => $status,
            'requires_review' => true,
            'evidence_required' => false,
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
