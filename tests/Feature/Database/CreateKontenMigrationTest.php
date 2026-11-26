<?php

namespace Tests\Feature\Database;

use Database\Seeders\AccountSeeder;
use Database\Seeders\CreativeDeskOptionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rollback and re-apply checks for the 8 Create Konten base migrations without RefreshDatabase,
 * avoiding MySQL implicit DDL commits inside a test transaction.
 */
class CreateKontenMigrationTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private const MIGRATION_PATHS = [
        'database/migrations/2026_11_25_100000_create_options_table.php',
        'database/migrations/2026_11_25_100001_create_contents_table.php',
        'database/migrations/2026_11_25_100002_create_events_table.php',
        'database/migrations/2026_11_25_100003_create_calendar_plans_table.php',
        'database/migrations/2026_11_25_100004_create_kpis_table.php',
        'database/migrations/2026_11_25_100005_create_user_apps_table.php',
        'database/migrations/2026_11_25_100006_create_footage_table.php',
        'database/migrations/2026_11_25_100007_create_drive_folders_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        // Force any subsequent RefreshDatabase test class to rebuild from a clean schema.
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_create_konten_migrations_roll_back_and_reapply_without_affecting_existing_tables(): void
    {
        $this->seed([
            RoleSeeder::class,
            AccountSeeder::class,
            CreativeDeskOptionSeeder::class,
        ]);

        $accountsBefore = DB::table('accounts')->count();

        // Roll back the SAV-42 events.reason migration first if present, then the 8 base tables.
        $reasonMigration = 'database/migrations/2026_11_25_110000_add_reason_to_events_table.php';
        if (file_exists(base_path($reasonMigration))) {
            $this->artisan('migrate:rollback', ['--path' => $reasonMigration])->assertSuccessful();
        }

        foreach (array_reverse(self::MIGRATION_PATHS) as $path) {
            $this->artisan('migrate:rollback', ['--path' => $path])->assertSuccessful();
        }

        foreach (['options', 'contents', 'events', 'calendar_plans', 'kpis', 'user_apps', 'footage', 'drive_folders'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table {$table} should be dropped after rollback");
        }

        $this->assertSame($accountsBefore, DB::table('accounts')->count());

        foreach (self::MIGRATION_PATHS as $path) {
            $this->artisan('migrate', ['--path' => $path])->assertSuccessful();
        }

        foreach (['options', 'contents', 'events', 'calendar_plans', 'kpis', 'user_apps', 'footage', 'drive_folders'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} should exist after re-migrate");
        }
    }
}
