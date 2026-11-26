<?php

namespace Tests\Feature\Database;

use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use Database\Seeders\CreativeDeskOptionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateKontenSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Account $marketingAccount;

    private Account $creativeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            CreativeDeskOptionSeeder::class,
        ]);

        $staffRoleId = Role::where('code_role', Role::EMPLOYEE)->value('id');
        $marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $creative = Division::create(['code_division' => 'CRT', 'name' => 'Creative']);

        $this->marketingAccount = Account::create([
            'role_id' => $staffRoleId,
            'division_id' => $marketing->id,
            'fullname' => 'Nadia Marketing',
            'username' => 'nadia.mkt',
            'password' => 'Secret123!',
            'is_active' => true,
        ]);

        $this->creativeAccount = Account::create([
            'role_id' => $staffRoleId,
            'division_id' => $creative->id,
            'fullname' => 'Dimas Creative',
            'username' => 'dimas.crt',
            'password' => 'Secret123!',
            'is_active' => true,
        ]);
    }

    public function test_all_eight_create_konten_tables_and_required_columns_exist_without_separate_users_table(): void
    {
        $this->assertFalse(Schema::hasTable('users'));

        $expected = [
            'options' => ['id', 'key', 'label', 'semantic', 'sort', 'archived', 'category', 'created_at', 'updated_at'],
            'contents' => [
                'id', 'title', 'app', 'type', 'type_no', 'created_date', 'upload_date',
                'script_status', 'talent_status', 'talent_name', 'creative_status', 'qc_status', 'priority',
                'link', 'notes', 'sheet', 'marketing_user_id', 'editor_account_id',
                'script_ready_at', 'talent_done_at', 'creative_done_at', 'link_at', 'qc_at',
                'published_url', 'published_date', 'published_by', 'archived', 'revision', 'created_at', 'updated_at',
            ],
            'events' => ['id', 'content_id', 'user_id', 'field', 'from_value', 'to_value', 'at'],
            'calendar_plans' => ['app', 'type', 'date', 'amount', 'color', 'created_at', 'updated_at'],
            'kpis' => ['id', 'user_id', 'app', 'type', 'work_date', 'upload_date', 'amount', 'created_at', 'updated_at'],
            'user_apps' => ['user_id', 'app'],
            'footage' => ['id', 'content_id', 'app', 'type', 'kind', 'title', 'url', 'file_path', 'mime', 'size', 'uploaded_by', 'created_at', 'updated_at'],
            'drive_folders' => ['app', 'type', 'url', 'created_at', 'updated_at'],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
            $actual = Schema::getColumnListing($table);
            foreach ($columns as $column) {
                $this->assertContains($column, $actual, "Missing column {$table}.{$column}");
            }
        }
    }

    public function test_option_seeder_populates_all_seven_keys_and_is_idempotent(): void
    {
        $this->assertSame(10, DB::table('options')->where('key', 'app')->count());
        $this->assertSame(2, DB::table('options')->where('key', 'talentName')->count());
        $this->assertSame(2, DB::table('options')->where('key', 'script')->count());
        $this->assertSame(3, DB::table('options')->where('key', 'talent')->count());
        $this->assertSame(2, DB::table('options')->where('key', 'creative')->count());
        $this->assertSame(3, DB::table('options')->where('key', 'qc')->count());
        $this->assertSame(3, DB::table('options')->where('key', 'priority')->count());

        DB::table('options')->where('id', 'app:1')->update(['label' => 'JadiASN Custom']);
        $this->seed(CreativeDeskOptionSeeder::class);

        $this->assertSame(25, DB::table('options')->count());
        $this->assertSame('JadiASN Custom', DB::table('options')->where('id', 'app:1')->value('label'));
    }

    public function test_contents_allows_nullable_upload_date_and_enforces_unique_type_no(): void
    {
        $id1 = $this->insertContent(['type' => 'Video', 'type_no' => 1, 'upload_date' => null]);
        $id2 = $this->insertContent(['type' => 'Carousel', 'type_no' => 1, 'upload_date' => '2026-11-30']);

        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan(0, $id2);
        $this->assertNull(DB::table('contents')->where('id', $id1)->value('upload_date'));

        $this->expectException(QueryException::class);
        $this->insertContent(['type' => 'Video', 'type_no' => 1]);
    }

    public function test_enforces_unique_kpi_grain_and_composite_primary_keys(): void
    {
        DB::table('kpis')->insert([
            'user_id' => $this->creativeAccount->id,
            'app' => 'app:1',
            'type' => 'Video',
            'work_date' => '2026-11-25',
            'upload_date' => '2026-11-26',
            'amount' => 3,
        ]);

        try {
            DB::table('kpis')->insert([
                'user_id' => $this->creativeAccount->id,
                'app' => 'app:1',
                'type' => 'Video',
                'work_date' => '2026-11-25',
                'upload_date' => '2026-11-27',
                'amount' => 5,
            ]);
            $this->fail('Duplicate KPI grain must fail');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('kpis')->count());
        }

        DB::table('calendar_plans')->insert([
            'app' => 'app:1',
            'type' => 'Video',
            'date' => '2026-11-25',
            'amount' => 4,
            'color' => '#3A5A40',
        ]);

        $this->expectException(QueryException::class);
        DB::table('calendar_plans')->insert([
            'app' => 'app:1',
            'type' => 'Video',
            'date' => '2026-11-25',
            'amount' => 6,
        ]);
    }

    public function test_foreign_keys_restrict_deleting_referenced_accounts_and_options_and_nullify_footage_content(): void
    {
        $contentId = $this->insertContent([
            'marketing_user_id' => $this->marketingAccount->id,
            'editor_account_id' => $this->creativeAccount->id,
        ]);

        $footageId = DB::table('footage')->insertGetId([
            'content_id' => $contentId,
            'app' => 'app:1',
            'type' => 'Video',
            'kind' => 'link',
            'title' => 'Raw Footage',
            'url' => 'https://drive.google.com/example',
            'uploaded_by' => $this->creativeAccount->id,
        ]);

        // Deleting an account referenced by contents must be blocked (ON DELETE RESTRICT).
        try {
            DB::table('accounts')->where('id', $this->creativeAccount->id)->delete();
            $this->fail('Deleting referenced editor account must fail');
        } catch (QueryException) {
            $this->assertDatabaseHas('accounts', ['id' => $this->creativeAccount->id]);
        }

        // Deleting an option referenced by contents must be blocked (ON DELETE RESTRICT).
        try {
            DB::table('options')->where('id', 'app:1')->delete();
            $this->fail('Deleting referenced option must fail');
        } catch (QueryException) {
            $this->assertDatabaseHas('options', ['id' => 'app:1']);
        }

        // Deleting content without events sets footage.content_id to NULL while keeping the footage row.
        DB::table('contents')->where('id', $contentId)->delete();
        $this->assertNull(DB::table('footage')->where('id', $footageId)->value('content_id'));
    }

    public function test_kpis_restrict_physical_account_delete_instead_of_cascading(): void
    {
        DB::table('kpis')->insert([
            'user_id' => $this->marketingAccount->id,
            'app' => 'app:1',
            'type' => 'Carousel',
            'work_date' => '2026-11-25',
            'upload_date' => '2026-11-28',
            'amount' => 2,
        ]);

        $this->expectException(QueryException::class);
        DB::table('accounts')->where('id', $this->marketingAccount->id)->delete();
    }

    public function test_deactivating_an_account_preserves_historical_content_editor_reference(): void
    {
        $contentId = $this->insertContent(['editor_account_id' => $this->creativeAccount->id]);

        $this->creativeAccount->update(['is_active' => false]);

        $this->assertDatabaseHas('contents', [
            'id' => $contentId,
            'editor_account_id' => $this->creativeAccount->id,
        ]);
        $this->assertFalse($this->creativeAccount->fresh()->is_active);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertContent(array $overrides = []): int
    {
        return DB::table('contents')->insertGetId(array_merge([
            'title' => 'Konten Uji',
            'app' => 'app:1',
            'type' => 'Video',
            'created_date' => '2026-11-25',
            'upload_date' => '2026-11-28',
            'script_status' => 'script:1',
            'talent_status' => 'talent:1',
            'talent_name' => 'talentName:1',
            'creative_status' => 'creative:1',
            'qc_status' => 'qc:1',
            'priority' => 'priority:1',
            'marketing_user_id' => $this->marketingAccount->id,
            'editor_account_id' => $this->creativeAccount->id,
            'archived' => false,
            'revision' => 1,
        ], $overrides));
    }
}
