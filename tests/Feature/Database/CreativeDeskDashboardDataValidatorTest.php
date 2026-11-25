<?php

namespace Tests\Feature\Database;

use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Services\CreativeDeskDashboardDataValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreativeDeskDashboardDataValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function createCreativeDeskSchema(string $editorColumn = 'editor_account_id', bool $withReason = true): void
    {
        Schema::create('options', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('key', 20);
            $table->string('label', 255);
            $table->string('semantic', 50)->default('');
            $table->integer('sort')->default(0);
            $table->integer('archived')->default(0);
            $table->string('category', 255)->nullable();
        });

        Schema::create('contents', function (Blueprint $table) use ($editorColumn) {
            $table->id();
            $table->text('title');
            $table->string('app', 64);
            $table->string('type', 20);
            $table->string('created_date', 30);
            $table->string('upload_date', 30);
            $table->string('script_status', 64);
            $table->string('talent_status', 64)->nullable();
            $table->string('talent_name', 64)->nullable();
            $table->string('creative_status', 64);
            $table->string('qc_status', 64);
            $table->string('priority', 64)->default('priority:1');
            $table->unsignedBigInteger('marketing_user_id')->nullable();
            $table->unsignedBigInteger($editorColumn)->nullable();
            $table->string('script_ready_at', 30)->nullable();
            $table->string('creative_done_at', 30)->nullable();
            $table->string('published_date', 30)->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->integer('archived')->default(0);
            $table->index('upload_date');
        });

        Schema::create('calendar_plans', function (Blueprint $table) {
            $table->string('app', 64);
            $table->string('type', 20);
            $table->string('date', 10);
            $table->integer('amount')->nullable();
            $table->string('color', 30)->nullable();
            $table->primary(['app', 'type', 'date']);
        });

        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('app', 64);
            $table->string('type', 20);
            $table->string('work_date', 10);
            $table->string('upload_date', 10);
            $table->integer('amount');
            $table->unique(['user_id', 'app', 'type', 'work_date']);
        });

        Schema::create('events', function (Blueprint $table) use ($withReason) {
            $table->id();
            $table->unsignedBigInteger('content_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('field', 255);
            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            if ($withReason) {
                $table->text('reason')->nullable();
            }
            $table->string('at', 30);
            $table->index(['content_id', 'at']);
        });
    }

    private function seedOptions(): void
    {
        DB::table('options')->insert([
            ['id' => 'app:1', 'key' => 'app', 'label' => 'JadiASN', 'semantic' => '', 'sort' => 0, 'archived' => 0, 'category' => 'JadiASN'],
            ['id' => 'script:2', 'key' => 'script', 'label' => 'Skrip ready', 'semantic' => 'Ready', 'sort' => 1, 'archived' => 0, 'category' => 'Skrip ready'],
            ['id' => 'creative:1', 'key' => 'creative', 'label' => 'Belum diedit', 'semantic' => 'Belum', 'sort' => 0, 'archived' => 0, 'category' => 'Belum diedit'],
            ['id' => 'qc:1', 'key' => 'qc', 'label' => 'Belum QC', 'semantic' => '', 'sort' => 0, 'archived' => 0, 'category' => 'Belum QC'],
            ['id' => 'priority:1', 'key' => 'priority', 'label' => 'Reguler', 'semantic' => 'Reguler', 'sort' => 0, 'archived' => 0, 'category' => 'Reguler'],
        ]);
    }

    public function test_reports_missing_creative_desk_tables_without_mutating_database(): void
    {
        $accountsBefore = DB::table('accounts')->count();
        $divisionsBefore = DB::table('divisions')->count();

        $validator = app(CreativeDeskDashboardDataValidator::class);
        $results = $validator->validate();
        $byCheck = collect($results)->keyBy('check');

        $this->assertFalse($validator->passed($results));
        $this->assertSame(CreativeDeskDashboardDataValidator::PASS, $byCheck['Tabel inti SiMarsel (accounts & divisions)']['status']);
        $this->assertSame(CreativeDeskDashboardDataValidator::FAIL, $byCheck['Tabel sumber CreativeDesk']['status']);
        $this->assertFalse(Schema::hasTable('contents'));
        $this->assertSame($accountsBefore, DB::table('accounts')->count());
        $this->assertSame($divisionsBefore, DB::table('divisions')->count());

        $this->artisan('creative-desk:validate-dashboard-data')->assertFailed();
    }

    public function test_passes_with_to_be_schema_and_warns_on_multi_kpi_calendar_plan_overlap(): void
    {
        $this->createCreativeDeskSchema('editor_account_id', true);
        $this->seedOptions();

        $mkt = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $crt = Division::create(['code_division' => 'CRT', 'name' => 'Creative']);
        $empRole = Role::where('code_role', Role::EMPLOYEE)->firstOrFail();

        $staffMkt = Account::create(['role_id' => $empRole->id, 'division_id' => $mkt->id, 'fullname' => 'Nadia', 'username' => 'nadia.mkt', 'password' => 'Secret123!', 'is_active' => true]);
        $staffCrt = Account::create(['role_id' => $empRole->id, 'division_id' => $crt->id, 'fullname' => 'Dimas', 'username' => 'dimas.crt', 'password' => 'Secret123!', 'is_active' => true]);

        DB::table('contents')->insert([
            'id' => 1, 'title' => 'Persiapan SKD', 'app' => 'app:1', 'type' => 'Video',
            'created_date' => '2026-11-23', 'upload_date' => '2026-11-26',
            'script_status' => 'script:2', 'creative_status' => 'creative:1', 'qc_status' => 'qc:1', 'priority' => 'priority:1',
            'marketing_user_id' => $staffMkt->id, 'editor_account_id' => $staffCrt->id, 'archived' => 0,
        ]);

        DB::table('calendar_plans')->insert(['app' => 'app:1', 'type' => 'Video', 'date' => '2026-11-23', 'amount' => 3]);
        DB::table('kpis')->insert([
            ['user_id' => $staffMkt->id, 'app' => 'app:1', 'type' => 'Video', 'work_date' => '2026-11-23', 'upload_date' => '2026-11-26', 'amount' => 3],
            ['user_id' => $staffCrt->id, 'app' => 'app:1', 'type' => 'Video', 'work_date' => '2026-11-23', 'upload_date' => '2026-11-25', 'amount' => 2],
        ]);
        DB::table('events')->insert(['content_id' => 1, 'user_id' => $staffMkt->id, 'field' => 'created', 'to_value' => 'Dibuat', 'reason' => null, 'at' => '2026-11-23 10:00:00']);

        $validator = app(CreativeDeskDashboardDataValidator::class);
        $results = $validator->validate();
        $byCheck = collect($results)->keyBy('check');

        $this->assertTrue($validator->passed($results));
        $this->assertSame(CreativeDeskDashboardDataValidator::PASS, $byCheck['Tabel sumber CreativeDesk']['status']);
        $this->assertSame(CreativeDeskDashboardDataValidator::PASS, $byCheck['Integritas referensi akun ke accounts.id']['status']);
        $this->assertSame(CreativeDeskDashboardDataValidator::WARN, $byCheck['Deteksi double-counting kpis vs calendar_plans']['status']);

        $this->artisan('creative-desk:validate-dashboard-data')->assertSuccessful();
    }

    public function test_detects_orphans_inactive_history_nullable_division_and_missing_events_reason(): void
    {
        $this->createCreativeDeskSchema('creative_user_id', false);
        $this->seedOptions();

        $empRole = Role::where('code_role', Role::EMPLOYEE)->firstOrFail();
        $inactive = Account::create(['role_id' => $empRole->id, 'division_id' => null, 'fullname' => 'Former Editor', 'username' => 'former.ed', 'password' => 'Secret123!', 'is_active' => false]);

        DB::table('contents')->insert([
            [
                'id' => 1, 'title' => 'Orphan Ref', 'app' => 'app:999', 'type' => 'Video',
                'created_date' => '2026-11-23', 'upload_date' => '2026-11-26',
                'script_status' => 'script:2', 'creative_status' => 'creative:1', 'qc_status' => 'qc:1', 'priority' => 'priority:1',
                'marketing_user_id' => 99999, 'creative_user_id' => 88888, 'archived' => 0,
            ],
            [
                'id' => 2, 'title' => 'Inactive History', 'app' => 'app:1', 'type' => 'Carousel',
                'created_date' => '2026-11-23', 'upload_date' => '2026-11-27',
                'script_status' => 'script:2', 'creative_status' => 'creative:1', 'qc_status' => 'qc:1', 'priority' => 'priority:1',
                'marketing_user_id' => null, 'creative_user_id' => $inactive->id, 'archived' => 0,
            ],
        ]);
        DB::table('events')->insert(['content_id' => 77777, 'user_id' => 66666, 'field' => 'upload_date', 'at' => '2026-11-24T09:00:00Z']);

        $validator = app(CreativeDeskDashboardDataValidator::class);
        $results = $validator->validate();
        $byCheck = collect($results)->keyBy('check');

        $this->assertFalse($validator->passed($results));
        $this->assertSame(CreativeDeskDashboardDataValidator::WARN, $byCheck['Kolom Dashboard pada contents']['status']);
        $this->assertSame(CreativeDeskDashboardDataValidator::FAIL, $byCheck['Referensi options pada contents']['status']);
        $this->assertSame(CreativeDeskDashboardDataValidator::FAIL, $byCheck['Integritas referensi akun ke accounts.id']['status']);
        $this->assertSame(CreativeDeskDashboardDataValidator::FAIL, $byCheck['Integritas events & kolom reason']['status']);
        $this->assertSame(CreativeDeskDashboardDataValidator::WARN, $byCheck['Akun nonaktif dengan histori konten']['status']);
        $this->assertSame(CreativeDeskDashboardDataValidator::WARN, $byCheck['Akun dengan division_id NULL']['status']);
    }
}
