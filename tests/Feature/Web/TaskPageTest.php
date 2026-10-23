<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskPageTest extends TestCase
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

    public function test_guest_cannot_open_tasklist(): void
    {
        $this->get('/tasks')->assertRedirect(route('login'));
    }

    public function test_every_role_can_open_tasklist_with_all_views(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get('/tasks')
                ->assertOk()
                ->assertSee('Tasklist')
                ->assertSee('x-data="tasklist"', false)
                ->assertSeeInOrder(['Total Tasks', 'In Progress', 'Overdue', 'Due Today'])
                ->assertSeeInOrder(['Completed', 'Unfinished', 'Urgent', 'Completion rate'])
                ->assertSee('data-metric="in_progress"', false)
                ->assertDontSee('data-metric="backlog"', false)
                ->assertSee('Lihat Detail')
                ->assertSee('Cari judul tugas')
                ->assertSee('Reset Filter')
                ->assertSee('x-data="taskTimeline"', false)
                ->assertSee('x-data="taskCalendar"', false)
                ->assertSee('x-data="taskDetail"', false)
                ->assertSee('Belum ada tugas yang tersedia.')
                ->assertSee('Tidak ada tugas yang sesuai dengan filter.')
                ->assertSee('Tidak ada deadline tugas pada tanggal ini.')
                ->assertSee('Menunggu pengerjaan Back End');
        }
    }

    public function test_board_has_six_workflow_columns_and_table_follows_review_column_order(): void
    {
        $this->actingAs($this->manager)
            ->get('/tasks')
            ->assertOk()
            ->assertSee('x-for="column in statuses"', false)
            ->assertSee('data-task-card', false)
            ->assertSeeInOrder(['Judul Tugas', 'Status', 'Prioritas', 'PIC', 'Deadline', 'Divisi', 'Aksi']);
    }

    public function test_create_task_form_lists_required_fields_without_points(): void
    {
        $this->actingAs($this->manager)
            ->get('/tasks')
            ->assertOk()
            ->assertSee('Create Task')
            ->assertSee('x-data="taskCreateForm"', false)
            ->assertSeeInOrder(['Judul Tugas', 'Deskripsi', 'Kategori / Jenis Tugas', 'Kewajiban QA', 'Platform', 'Prioritas', 'Target Divisi', 'PIC', 'Tanggal Mulai', 'Deadline', 'Status Awal'])
            ->assertDontSee('Poin');
    }

    public function test_sidebar_links_tasklist_for_every_role(): void
    {
        foreach ([$this->manager, $this->employee] as $account) {
            $this->actingAs($account)
                ->get('/tasks')
                ->assertOk()
                ->assertSee('href="'.route('tasks.index').'"', false)
                ->assertSee('aria-current="page"', false);
        }
    }
}
