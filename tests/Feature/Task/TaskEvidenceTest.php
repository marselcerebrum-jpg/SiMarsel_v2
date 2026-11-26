<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskCategory;
use App\Models\TaskEvidence;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskEvidenceTest extends TestCase
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

        Storage::fake('local');
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

    // Pengiriman evidence

    public function test_pic_submits_a_file_as_evidence(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $response = $this->actingAs($this->staff)
            ->postJson($this->url($task), [
                'file' => UploadedFile::fake()->create('laporan.pdf', 500, 'application/pdf'),
                'note' => 'Laporan final',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Bukti tugas berhasil dikirim.')
            ->assertJsonPath('data.task_id', $task->id)
            ->assertJsonPath('data.type', 'file')
            ->assertJsonPath('data.file_name', 'laporan.pdf')
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.note', 'Laporan final')
            ->assertJsonPath('data.submitter.id', $this->staff->id)
            ->assertJsonPath('data.created_at', now()->toIso8601ZuluString());

        $evidence = TaskEvidence::sole();

        $response->assertJsonPath('data.file_url', route('api.tasks.evidences.download', [$task->id, $evidence->id]));
        Storage::disk('local')->assertExists($evidence->file_path);
        $this->assertStringStartsWith("task-evidences/{$task->id}/", $evidence->file_path);
    }

    public function test_pic_submits_a_link_as_evidence(): void
    {
        $task = $this->task(TaskStatus::Todo);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['url' => 'https://drive.example.com/hasil'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'link')
            ->assertJsonPath('data.url', 'https://drive.example.com/hasil')
            ->assertJsonPath('data.file_name', null)
            ->assertJsonPath('data.file_url', null);

        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'submitter_id' => $this->staff->id,
            'type' => TaskEvidence::LINK,
            'file_path' => null,
        ]);
    }

    public function test_submitted_evidence_is_recorded_in_the_history(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)->postJson($this->url($task), ['url' => 'https://example.com/hasil']);

        $evidence = TaskEvidence::sole();
        $activity = TaskActivity::where('action', TaskActivity::EVIDENCE_SUBMITTED)->sole();

        $this->assertSame($task->id, $activity->task_id);
        $this->assertSame($this->staff->id, $activity->account_id);
        // assertEquals: the raw JSON column does not keep key order on MySQL.
        $this->assertEquals(
            ['evidence' => ['old' => null, 'new' => ['id' => $evidence->id, 'type' => 'link', 'name' => 'https://example.com/hasil']]],
            $activity->changes,
        );
    }

    public function test_submitting_evidence_does_not_change_the_task(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)->postJson($this->url($task), ['url' => 'https://example.com/hasil'])->assertCreated();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::InProgress, $fresh->status);
        $this->assertSame($task->version, $fresh->version);
    }

    // Akses

    public function test_guest_cannot_submit_evidence(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->postJson($this->url($task), ['url' => 'https://example.com'])->assertUnauthorized();
    }

    public function test_only_the_pic_may_submit_evidence(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        foreach ([$this->otherStaff, $this->manager, $this->admin, $this->financeManager] as $account) {
            $this->actingAs($account)
                ->postJson($this->url($task), ['url' => 'https://example.com/hasil'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_evidence_is_rejected_when_the_pic_is_replaced_while_it_is_being_sent(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        // The route lets the old PIC through, then the Manager reassigns the task before the evidence is saved.
        Gate::after(function (Account $user, string $ability) use ($task) {
            if ($ability === 'submitEvidence') {
                $task->activeAssignments()->update(['unassigned_at' => now(), 'unassigned_by' => $this->manager->id]);
                $task->assignments()->create(['account_id' => $this->otherStaff->id, 'assigned_by' => $this->manager->id, 'assigned_at' => now()]);
            }
        });

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => UploadedFile::fake()->create('laporan.pdf', 10, 'application/pdf')])
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki akses untuk melakukan aksi ini.');

        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertSame(0, $task->activities()->where('action', TaskActivity::EVIDENCE_SUBMITTED)->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_the_new_pic_may_send_evidence_after_reassignment(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->manager)
            ->patchJson("/api/tasks/{$task->id}/assignment", ['version' => $task->version, 'pic_ids' => [$this->otherStaff->id]])
            ->assertOk();

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['url' => 'https://example.com/lama'])
            ->assertForbidden();

        $this->actingAs($this->otherStaff)
            ->postJson($this->url($task), ['url' => 'https://example.com/baru'])
            ->assertCreated()
            ->assertJsonPath('data.submitter.id', $this->otherStaff->id);

        $this->assertSame(['https://example.com/baru'], $task->evidences()->pluck('url')->all());
    }

    public function test_evidence_cannot_be_sent_for_another_task_through_the_pic_task(): void
    {
        $own = $this->task(TaskStatus::InProgress);
        $other = $this->task(TaskStatus::InProgress, [], [$this->otherStaff]);

        $this->actingAs($this->staff)
            ->postJson($this->url($own), ['url' => 'https://example.com/hasil', 'task_id' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.task_id', $own->id);

        $this->assertSame(0, $other->evidences()->count());
    }

    public function test_admin_and_division_manager_see_the_evidence(): void
    {
        $task = $this->task(TaskStatus::InProgress);
        $this->evidence($task);

        foreach ([$this->admin, $this->manager, $this->staff] as $account) {
            $this->actingAs($account)
                ->getJson($this->url($task))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.submitter.fullname', $this->staff->fullname);
        }
    }

    public function test_accounts_outside_the_task_see_its_evidence_but_cannot_submit_any(): void
    {
        $task = $this->task(TaskStatus::InProgress);
        $evidence = $this->evidence($task);

        foreach ([$this->financeManager, $this->otherStaff] as $account) {
            $this->actingAs($account)->getJson($this->url($task))->assertOk()->assertJsonPath('data.0.id', $evidence->id);
            $this->actingAs($account)
                ->postJson($this->url($task), ['type' => 'link', 'url' => 'https://example.com/hasil', 'version' => $task->fresh()->version])
                ->assertForbidden();
        }
    }

    // Validasi file dan tautan

    public function test_file_or_link_is_required(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['note' => 'Tanpa bukti'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'file' => 'Lampirkan file atau tautan bukti.',
                'url' => 'Lampirkan file atau tautan bukti.',
            ]);
    }

    public function test_file_and_link_cannot_be_sent_together(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), [
                'file' => UploadedFile::fake()->create('laporan.pdf', 10, 'application/pdf'),
                'url' => 'https://example.com/hasil',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Kirim file atau tautan saja, tidak keduanya.']);

        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_file_type_must_be_allowed(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $files = [
            UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('foto.webp', 10, 'image/webp'),
            UploadedFile::fake()->create('data.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            UploadedFile::fake()->create('slide.pptx', 10, 'application/vnd.openxmlformats-officedocument.presentationml.presentation'),
            UploadedFile::fake()->create('arsip.zip', 10, 'application/zip'),
            UploadedFile::fake()->create('ikon.svg', 10, 'image/svg+xml'),
        ];

        foreach ($files as $file) {
            $this->actingAs($this->staff)
                ->postJson($this->url($task), ['file' => $file])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['file' => 'Jenis file tidak didukung. Format yang diperbolehkan: PDF, DOC, DOCX, JPG, JPEG, atau PNG.']);
        }

        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_every_allowed_document_and_image_type_is_accepted_with_its_metadata(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $files = [
            ['laporan.pdf', 'application/pdf', 'document'],
            ['laporan.doc', 'application/msword', 'document'],
            ['laporan.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document'],
            ['foto.jpg', 'image/jpeg', 'image'],
            ['foto.jpeg', 'image/jpeg', 'image'],
            ['foto.png', 'image/png', 'image'],
        ];

        foreach ($files as [$name, $mime, $kind]) {
            $response = $this->actingAs($this->staff)
                ->postJson($this->url($task), ['file' => UploadedFile::fake()->create($name, 12, $mime)])
                ->assertCreated()
                ->assertJsonPath('data.type', 'file')
                ->assertJsonPath('data.kind', $kind)
                ->assertJsonPath('data.file_name', $name)
                ->assertJsonPath('data.mime_type', $mime)
                ->assertJsonPath('data.file_size', 12 * 1024);

            $evidence = TaskEvidence::findOrFail($response->json('data.id'));
            $this->assertSame($mime, $evidence->mime_type);
            $this->assertSame(12 * 1024, $evidence->file_size);
            $response->assertJsonPath(
                'data.preview_url',
                $kind === 'image' ? route('api.tasks.evidences.preview', [$task->id, $evidence->id]) : null,
            );
        }

        $this->assertDatabaseCount('task_evidences', 6);
    }

    public function test_the_stored_mime_type_comes_from_the_content_not_from_the_browser(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => $this->realFile('foto.png', self::PNG, 'application/pdf')])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.mime_type', 'image/png');

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => $this->realFile('laporan.pdf', self::PDF, 'application/octet-stream')])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'document')
            ->assertJsonPath('data.mime_type', 'application/pdf');
    }

    public function test_a_file_whose_content_does_not_match_its_extension_is_rejected(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $files = [
            $this->realFile('foto.png', self::PDF, 'image/png'),
            $this->realFile('laporan.pdf', self::PNG, 'application/pdf'),
            $this->realFile('laporan.pdf', '<?php echo "x";', 'application/pdf'),
        ];

        foreach ($files as $file) {
            $this->actingAs($this->staff)
                ->postJson($this->url($task), ['file' => $file])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file');
        }

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => $this->realFile('foto.png', self::PDF, 'image/png')])
            ->assertJsonValidationErrors(['file' => 'Isi file tidak sesuai dengan ekstensinya. Unggah file asli tanpa mengganti ekstensinya.']);

        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_size_limit_follows_the_storage_configuration(): void
    {
        config(['tasklist.evidence.max_kilobytes' => 500]);
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => UploadedFile::fake()->create('besar.pdf', 501, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Ukuran file maksimal 500 KB.']);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => UploadedFile::fake()->create('pas.pdf', 500, 'application/pdf')])
            ->assertCreated();
    }

    public function test_file_may_not_exceed_ten_megabytes(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => UploadedFile::fake()->create('besar.pdf', 10241, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Ukuran file maksimal 10 MB.']);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => UploadedFile::fake()->create('pas.pdf', 10240, 'application/pdf')])
            ->assertCreated();
    }

    public function test_link_must_be_a_valid_http_or_https_url(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        foreach (['bukan tautan', 'ftp://example.com/file', 'javascript:alert(1)'] as $url) {
            $this->actingAs($this->staff)
                ->postJson($this->url($task), ['url' => $url])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['url' => 'Tautan harus berupa URL yang valid dan diawali http:// atau https://.']);
        }

        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_note_is_limited(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['url' => 'https://example.com', 'note' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note' => 'Catatan maksimal 2000 karakter.']);
    }

    public function test_evidence_is_rejected_once_the_task_has_left_the_pic(): void
    {
        foreach ([TaskStatus::ReadyReview, TaskStatus::OnReview, TaskStatus::Done] as $status) {
            $task = $this->task($status);

            $this->actingAs($this->staff)
                ->postJson($this->url($task), ['file' => UploadedFile::fake()->create('laporan.pdf', 10, 'application/pdf')])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'evidence' => "Evidence hanya dapat dikirim saat tugas berstatus Back Log, To Do, atau On Process, bukan {$status->label()}.",
                ]);
        }

        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // Submission

    public function test_task_requiring_qa_goes_to_ready_review_only_after_evidence(): void
    {
        $task = $this->task(TaskStatus::InProgress, ['evidence_required' => true, 'requires_review' => true]);

        $this->actingAs($this->staff)
            ->patchJson($this->statusUrl($task), ['version' => $task->version, 'status' => 'ready_review'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->postJson($this->url($task), ['url' => 'https://example.com/hasil'])->assertCreated();

        $this->patchJson($this->statusUrl($task), ['version' => $task->version, 'status' => 'ready_review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_review')
            ->assertJsonPath('data.evidence_count', 1);
    }

    public function test_task_without_review_is_completed_by_a_reviewer_after_the_pic_sends_evidence(): void
    {
        $task = $this->task(TaskStatus::InProgress, ['evidence_required' => true, 'requires_review' => false]);

        $this->actingAs($this->manager)
            ->patchJson($this->statusUrl($task), ['version' => $task->version, 'status' => 'done'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Unggah evidence terlebih dahulu sebelum mengubah status ke Done.']);

        $this->actingAs($this->staff)->postJson($this->url($task), ['file' => UploadedFile::fake()->image('hasil.png')])->assertCreated();

        $this->actingAs($this->manager)
            ->patchJson($this->statusUrl($task), ['version' => $task->version, 'status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_at', now()->toIso8601ZuluString());
    }

    public function test_task_with_optional_evidence_may_be_submitted_without_it(): void
    {
        $task = $this->task(TaskStatus::InProgress, ['evidence_required' => false]);

        $this->actingAs($this->staff)
            ->patchJson($this->statusUrl($task), ['version' => $task->version, 'status' => 'ready_review'])
            ->assertOk()
            ->assertJsonPath('data.evidence_count', 0);
    }

    public function test_pic_resubmits_after_revision_and_earlier_evidence_stays_traceable(): void
    {
        $task = $this->task(TaskStatus::InProgress, ['evidence_required' => true, 'requires_review' => true]);

        $this->actingAs($this->staff)->postJson($this->url($task), ['url' => 'https://example.com/v1'])->assertCreated();
        $this->moveTo($task, $this->staff, 'ready_review');
        $this->moveTo($task, $this->manager, 'on_review');
        $this->actingAs($this->manager)
            ->postJson("/api/tasks/{$task->id}/reviews", ['version' => $task->fresh()->version, 'decision' => 'revision', 'note' => 'Perbaiki data.'])
            ->assertOk();

        // After a revision the task is in Back Log; the PIC sends the fix without moving it first.
        $this->actingAs($this->staff)->postJson($this->url($task), ['url' => 'https://example.com/v2'])->assertCreated();
        $this->assertSame(TaskStatus::Backlog, $task->fresh()->status);
        $this->moveTo($task, $this->staff, 'todo');
        $this->moveTo($task, $this->staff, 'in_progress');
        $this->moveTo($task, $this->staff, 'ready_review');

        $this->actingAs($this->manager)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_review')
            ->assertJsonPath('data.evidence_count', 2)
            ->assertJsonPath('data.evidence.0.url', 'https://example.com/v2')
            ->assertJsonPath('data.evidence.1.url', 'https://example.com/v1');
    }

    // Detail, daftar, dan unduhan

    public function test_task_detail_and_list_show_the_evidence(): void
    {
        $task = $this->task(TaskStatus::InProgress);
        $this->evidence($task);

        $this->actingAs($this->staff)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.evidence')
            ->assertJsonPath('data.evidence.0.type', 'link')
            ->assertJsonPath('data.evidence_count', 1);

        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.evidence_count', 1);
    }

    public function test_file_evidence_is_downloaded_by_those_who_see_the_task(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => UploadedFile::fake()->createWithContent('catatan.pdf', 'isi bukti')])
            ->assertCreated();

        $evidence = TaskEvidence::sole();

        $this->actingAs($this->manager)
            ->get($this->downloadUrl($task, $evidence))
            ->assertOk()
            ->assertDownload('catatan.pdf');
    }

    public function test_evidence_of_another_task_is_not_found(): void
    {
        $task = $this->task(TaskStatus::InProgress);
        $other = $this->task(TaskStatus::InProgress);
        $evidence = $this->evidence($other, ['type' => TaskEvidence::FILE, 'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'url' => null]);
        Storage::disk('local')->put('x.pdf', 'isi');

        $this->actingAs($this->staff)->get($this->downloadUrl($task, $evidence))->assertNotFound();
    }

    public function test_link_evidence_has_nothing_to_download(): void
    {
        $task = $this->task(TaskStatus::InProgress);
        $evidence = $this->evidence($task);

        $this->actingAs($this->staff)->get($this->downloadUrl($task, $evidence))->assertNotFound();
        $this->actingAs($this->staff)->get($this->previewUrl($task, $evidence))->assertNotFound();
    }

    public function test_download_tells_the_browser_not_to_guess_the_type(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => UploadedFile::fake()->createWithContent('catatan.pdf', 'isi bukti')])
            ->assertCreated();

        $this->get($this->downloadUrl($task, TaskEvidence::sole()))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_an_image_is_previewed_inline_by_everyone_who_sees_the_task(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => $this->realFile('foto.png', self::PNG, 'image/png')])
            ->assertCreated();

        $evidence = TaskEvidence::sole();

        foreach ([$this->manager, $this->financeManager, $this->otherStaff] as $account) {
            $response = $this->actingAs($account)
                ->get($this->previewUrl($task, $evidence))
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff');

            $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        }

        auth()->logout();
        $this->getJson($this->previewUrl($task, $evidence))->assertUnauthorized();
    }

    public function test_a_document_has_no_preview_and_is_opened_through_its_download(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => UploadedFile::fake()->createWithContent('catatan.pdf', 'isi bukti')])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'document')
            ->assertJsonPath('data.preview_url', null);

        $this->get($this->previewUrl($task, TaskEvidence::sole()))->assertNotFound();
    }

    public function test_a_deleted_image_can_no_longer_be_previewed(): void
    {
        $task = $this->task(TaskStatus::InProgress);

        $this->actingAs($this->staff)
            ->postJson($this->url($task), ['file' => $this->realFile('foto.png', self::PNG, 'image/png')])
            ->assertCreated();
        $evidence = TaskEvidence::sole();

        $this->deleteJson("/api/tasks/{$task->id}/evidences/{$evidence->id}")->assertOk();

        $this->get($this->previewUrl($task, $evidence))->assertNotFound();
        Storage::disk('local')->assertExists($evidence->file_path);
    }

    public function test_pic_adds_and_deletes_their_evidence_in_back_log_without_moving_the_task(): void
    {
        $task = $this->task(TaskStatus::Backlog);

        $id = $this->actingAs($this->staff)
            ->postJson($this->url($task), ['url' => 'https://example.com/draft'])
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/tasks/{$task->id}/evidences/{$id}")->assertOk();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Backlog, $fresh->status);
        $this->assertSame($task->version, $fresh->version);
    }

    // Ketentuan jenis tugas

    public function test_category_that_requires_evidence_makes_new_tasks_require_it(): void
    {
        $category = TaskCategory::where('name', 'Riset')->firstOrFail();
        $this->assertTrue($category->evidence_required);

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', ['title' => 'Riset kompetitor', 'division_id' => $this->marketing->id, 'category_id' => $category->id, 'evidence_required' => false])
            ->assertCreated()
            ->assertJsonPath('data.evidence_required', true)
            ->assertJsonPath('data.category.evidence_required', true);
    }

    public function test_creator_may_require_evidence_when_the_category_does_not(): void
    {
        $category = TaskCategory::where('name', 'Administrasi')->firstOrFail();

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', ['title' => 'Rekap absensi', 'division_id' => $this->marketing->id, 'category_id' => $category->id])
            ->assertCreated()
            ->assertJsonPath('data.evidence_required', false);

        $this->actingAs($this->manager)
            ->postJson('/api/tasks', ['title' => 'Rekap lembur', 'division_id' => $this->marketing->id, 'category_id' => $category->id, 'evidence_required' => true])
            ->assertCreated()
            ->assertJsonPath('data.evidence_required', true);
    }

    public function test_moving_a_task_to_a_category_that_requires_evidence_requires_it(): void
    {
        $task = $this->task(TaskStatus::Todo, ['category_id' => TaskCategory::where('name', 'Administrasi')->value('id')]);

        $this->actingAs($this->manager)
            ->patchJson("/api/tasks/{$task->id}", ['version' => $task->version, 'category_id' => TaskCategory::where('name', 'Riset')->value('id')])
            ->assertOk()
            ->assertJsonPath('data.evidence_required', true);
    }

    public function test_create_options_show_the_evidence_rule_of_each_category(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/tasks/create-options')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Riset', 'requires_review' => false, 'evidence_required' => true])
            ->assertJsonFragment(['name' => 'Administrasi', 'requires_review' => false, 'evidence_required' => false]);
    }

    private function moveTo(Task $task, Account $actor, string $status): void
    {
        $this->actingAs($actor)
            ->patchJson($this->statusUrl($task), ['version' => $task->fresh()->version, 'status' => $status])
            ->assertOk();
    }

    /**
     * A 1×1 PNG and the start of a PDF, so the type is detected from real content.
     */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa75\x81\x84\x00\x00\x00\x00IEND\xaeB`\x82";

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /**
     * An upload backed by a real file, so its type is detected from the content like in production; the browser's
     * claimed type is ignored by the server.
     */
    private function realFile(string $name, string $content, string $clientMimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $clientMimeType, null, true);
    }

    private function previewUrl(Task $task, TaskEvidence $evidence): string
    {
        return "/api/tasks/{$task->id}/evidences/{$evidence->id}/preview";
    }

    private function url(Task $task): string
    {
        return "/api/tasks/{$task->id}/evidences";
    }

    private function statusUrl(Task $task): string
    {
        return "/api/tasks/{$task->id}/status";
    }

    private function downloadUrl(Task $task, TaskEvidence $evidence): string
    {
        return "/api/tasks/{$task->id}/evidences/{$evidence->id}/download";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(TaskStatus $status, array $attributes = [], ?array $pics = null): Task
    {
        return Task::factory()->withPics(...($pics ?? [$this->staff]))->create([
            'division_id' => $this->marketing->id,
            'creator_id' => $this->manager->id,
            'status' => $status,
            'requires_review' => true,
            'evidence_required' => false,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function evidence(Task $task, array $attributes = []): TaskEvidence
    {
        return $task->evidences()->create([
            'submitter_id' => $this->staff->id,
            'type' => TaskEvidence::LINK,
            'url' => 'https://example.com/hasil',
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
