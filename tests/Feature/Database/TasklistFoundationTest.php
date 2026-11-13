<?php

namespace Tests\Feature\Database;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAssignment;
use App\Models\TaskComment;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TasklistFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private Account $manager;

    private Account $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->division = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->manager = $this->account(Role::MANAGER, 'manager');
        $this->staff = $this->account(Role::EMPLOYEE, 'staff');
    }

    public function test_pic_id_is_backfilled_into_active_assignments_without_touching_pic_id(): void
    {
        $migration = $this->migration('create_task_assignments_table');
        $migration->down();

        // Legacy rows: pic_id is written directly, as before task_assignments existed.
        $withHistory = $this->legacyTask($this->staff);
        $withoutHistory = $this->legacyTask($this->staff);
        $unassigned = $this->legacyTask(null);

        $admin = $this->account(Role::ADMIN, 'admin');
        DB::table('task_activities')->insert([
            ['task_id' => $withHistory->id, 'account_id' => $this->manager->id, 'action' => TaskActivity::ASSIGNED, 'created_at' => '2026-11-01 08:00:00'],
            ['task_id' => $withHistory->id, 'account_id' => $admin->id, 'action' => TaskActivity::REASSIGNED, 'created_at' => '2026-11-02 09:00:00'],
        ]);

        $migration->up();

        $this->assertSame(2, TaskAssignment::count());
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $withHistory->id, 'account_id' => $this->staff->id, 'assigned_by' => $admin->id,
            'assigned_at' => '2026-11-02 09:00:00', 'unassigned_at' => null,
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $withoutHistory->id, 'account_id' => $this->staff->id, 'assigned_by' => $this->manager->id,
        ]);
        $this->assertSame(0, TaskAssignment::where('task_id', $unassigned->id)->count());
        $this->assertSame($this->staff->id, $withHistory->fresh()->pic_id);
        $this->assertEquals([$this->staff->id], $withHistory->pics()->pluck('accounts.id')->all());
    }

    public function test_an_account_cannot_be_an_active_pic_of_the_same_task_twice(): void
    {
        $task = Task::factory()->create(['division_id' => $this->division->id]);
        $this->assign($task, $this->staff, ['unassigned_at' => now()->subDay(), 'unassigned_by' => $this->manager->id]);
        $this->assign($task, $this->staff);
        $this->assign($task, $this->manager);

        $this->assertSame(3, $task->assignments()->count());
        $this->assertSame(2, $task->pics()->count());

        $this->expectException(QueryException::class);
        $this->assign($task, $this->staff);
    }

    public function test_ending_an_assignment_keeps_its_history(): void
    {
        $task = Task::factory()->create(['division_id' => $this->division->id]);
        $assignment = $this->assign($task, $this->staff);

        $assignment->update(['unassigned_at' => now(), 'unassigned_by' => $this->manager->id]);

        $this->assertSame(0, $task->pics()->count());
        $this->assertFalse($assignment->fresh()->isActive());
        $this->assertSame(1, $task->assignments()->count());
    }

    public function test_qa_statuses_and_their_history_are_renamed_and_can_be_rolled_back(): void
    {
        $readyTask = Task::factory()->create(['division_id' => $this->division->id]);
        $onTask = Task::factory()->create(['division_id' => $this->division->id]);
        DB::table('tasks')->where('id', $readyTask->id)->update(['status' => 'ready_for_qa']);
        DB::table('tasks')->where('id', $onTask->id)->update(['status' => 'on_qa']);
        $changes = ['status' => ['old' => 'ready_for_qa', 'new' => 'on_qa'], 'title' => ['old' => 'on_qa', 'new' => 'Judul']];
        $activityId = DB::table('task_activities')->insertGetId([
            'task_id' => $onTask->id, 'account_id' => $this->manager->id, 'action' => TaskActivity::STATUS_CHANGED,
            'changes' => json_encode($changes), 'created_at' => now(),
        ]);

        $migration = $this->migration('rename_qa_statuses_to_review_statuses');
        $migration->up();

        $this->assertSame(TaskStatus::ReadyReview, $readyTask->fresh()->status);
        $this->assertSame(TaskStatus::OnReview, $onTask->fresh()->status);
        $renamed = json_decode(DB::table('task_activities')->where('id', $activityId)->value('changes'), true);
        $this->assertSame(['old' => 'ready_review', 'new' => 'on_review'], $renamed['status']);
        $this->assertSame('on_qa', $renamed['title']['old'], 'Only status values are renamed.');
        $this->assertStringStartsWith('Status: Ready Review → On Review', TaskActivity::find($activityId)->description());

        $migration->down();

        $this->assertSame('ready_for_qa', DB::table('tasks')->where('id', $readyTask->id)->value('status'));
        $this->assertSame('on_qa', DB::table('tasks')->where('id', $onTask->id)->value('status'));
        $this->assertSame($changes, json_decode(DB::table('task_activities')->where('id', $activityId)->value('changes'), true));
    }

    public function test_a_deleted_task_leaves_the_active_list_but_keeps_its_related_data(): void
    {
        $task = Task::factory()->create(['division_id' => $this->division->id]);
        $this->assign($task, $this->staff);
        TaskComment::create(['task_id' => $task->id, 'author_id' => $this->staff->id, 'content' => 'Sudah dikerjakan.']);

        $task->update(['deleted_by' => $this->manager->id]);
        $task->delete();

        $this->assertNull(Task::find($task->id));
        $trashed = Task::withTrashed()->find($task->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame($this->manager->id, $trashed->deleted_by);
        $this->assertSame(1, $trashed->assignments()->count());
        $this->assertSame(1, $trashed->comments()->count());
    }

    public function test_comment_replies_and_collaborating_divisions_are_linked_to_the_task(): void
    {
        $task = Task::factory()->create(['division_id' => $this->division->id]);
        $finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);
        $task->collaboratingDivisions()->attach($finance->id, ['added_by' => $this->manager->id]);

        $comment = TaskComment::create(['task_id' => $task->id, 'author_id' => $this->manager->id, 'content' => 'Mohon dicek.']);
        $reply = TaskComment::create(['task_id' => $task->id, 'author_id' => $this->staff->id, 'parent_comment_id' => $comment->id, 'content' => 'Siap.']);

        $this->assertEquals([$finance->id], $task->collaboratingDivisions()->pluck('divisions.id')->all());
        $this->assertTrue($comment->replies->first()->is($reply));
        $this->assertTrue($reply->parent->is($comment));
    }

    public function test_role_seeder_adds_the_new_roles_without_changing_existing_accounts(): void
    {
        $employeeRoleId = $this->staff->role_id;

        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing(
            [Role::ADMIN, Role::MANAGER, Role::EMPLOYEE, Role::SUPERVISOR, Role::FREELANCE],
            Role::pluck('code_role')->all(),
        );
        $this->assertSame('Staff', Role::where('code_role', Role::EMPLOYEE)->value('name'));
        $this->assertSame($employeeRoleId, $this->staff->fresh()->role_id);
        $this->assertTrue($this->staff->fresh()->is_active);
    }

    public function test_permission_seeder_grants_task_permissions_per_role_and_can_run_again(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->assertSame(7, Permission::count());

        foreach ([Role::ADMIN, Role::MANAGER, Role::SUPERVISOR] as $code) {
            $this->assertSame(7, Role::where('code_role', $code)->first()->permissions()->count(), $code);
        }

        foreach ([Role::EMPLOYEE, Role::FREELANCE] as $code) {
            $role = Role::where('code_role', $code)->first();
            $this->assertTrue($role->hasPermission(Permission::TASK_VIEW_ALL), $code);
            $this->assertTrue($role->hasPermission(Permission::TASK_CHANGE_STATUS), $code);
            $this->assertFalse($role->hasPermission(Permission::TASK_CREATE), $code);
            $this->assertFalse($role->hasPermission(Permission::TASK_REVIEW), $code);
        }
    }

    private function legacyTask(?Account $pic): Task
    {
        return Task::find(DB::table('tasks')->insertGetId([
            'title' => 'Tugas lama',
            'division_id' => $this->division->id,
            'pic_id' => $pic?->id,
            'creator_id' => $this->manager->id,
            'priority' => 'medium',
            'status' => 'todo',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function migration(string $name): Migration
    {
        return require collect(glob(database_path("migrations/*_{$name}.php")))->sole();
    }

    private function assign(Task $task, Account $account, array $attributes = []): TaskAssignment
    {
        return TaskAssignment::create([
            'task_id' => $task->id,
            'account_id' => $account->id,
            'assigned_by' => $this->manager->id,
            'assigned_at' => now(),
            ...$attributes,
        ]);
    }

    private function account(string $role, string $username): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $this->division->id,
            'fullname' => ucfirst($username),
            'username' => $username,
            'password' => 'secret123',
        ]);
    }
}
