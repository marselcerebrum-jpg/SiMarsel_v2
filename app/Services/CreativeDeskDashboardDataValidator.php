<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only validation of CreativeDesk Dashboard sources against the To-Be ERD and runtime DB (SAV-15).
 * Never runs migrations, backfills, or writes.
 */
class CreativeDeskDashboardDataValidator
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    public const SKIP = 'SKIP';

    private const CREATIVE_DESK_TABLES = ['options', 'contents', 'kpis', 'calendar_plans', 'events'];

    private const REQUIRED_COLUMNS = [
        'accounts' => ['id', 'division_id', 'fullname', 'username', 'is_active'],
        'divisions' => ['id', 'code_division', 'name'],
        'options' => ['id', 'key', 'label', 'semantic', 'sort', 'archived', 'category'],
        'contents' => [
            'id', 'title', 'app', 'type', 'created_date', 'upload_date',
            'script_status', 'creative_status', 'qc_status', 'priority',
            'script_ready_at', 'creative_done_at', 'published_date', 'archived',
        ],
        'kpis' => ['id', 'user_id', 'app', 'type', 'work_date', 'upload_date', 'amount'],
        'calendar_plans' => ['app', 'type', 'date', 'amount'],
        'events' => ['id', 'content_id', 'user_id', 'field', 'from_value', 'to_value', 'at'],
    ];

    /**
     * @return array<int, array{check: string, status: string, detail: string}>
     */
    public function validate(): array
    {
        return [
            $this->simarselCoreTables(),
            $this->creativeDeskTables(),
            $this->creativeDeskDivisions(),
            $this->accountsNullableDivision(),
            $this->optionsAppsMaster(),
            $this->contentsSchema(),
            $this->contentsOptionReferences(),
            $this->kpiAndCalendarPlanGrain(),
            $this->kpiCalendarPlanOverlap(),
            $this->targetLogicalAppReferences(),
            $this->eventsIntegrity(),
            $this->accountReferencesIntegrity(),
            $this->inactiveAccountsWithHistory(),
            $this->indexesAndForeignKeys(),
        ];
    }

    /**
     * @param  array<int, array{status: string}>  $results
     */
    public function passed(array $results): bool
    {
        return collect($results)->doesntContain('status', self::FAIL);
    }

    private function simarselCoreTables(): array
    {
        $issues = $this->missingTableOrColumns(['accounts', 'divisions']);

        if ($issues !== []) {
            return $this->result('Tabel inti SiMarsel (accounts & divisions)', self::FAIL, implode('; ', $issues));
        }

        $accounts = DB::table('accounts')->count();
        $divisions = DB::table('divisions')->count();

        return $this->result('Tabel inti SiMarsel (accounts & divisions)', self::PASS, "accounts={$accounts}, divisions={$divisions}");
    }

    private function creativeDeskTables(): array
    {
        $missing = array_values(array_filter(self::CREATIVE_DESK_TABLES, fn (string $table) => ! Schema::hasTable($table)));

        if ($missing !== []) {
            return $this->result(
                'Tabel sumber CreativeDesk',
                self::FAIL,
                '[SAV-48/SAV-10] Belum tersedia di runtime DB: '.implode(', ', $missing),
            );
        }

        $counts = array_map(fn (string $table) => "{$table}=".DB::table($table)->count(), self::CREATIVE_DESK_TABLES);

        return $this->result('Tabel sumber CreativeDesk', self::PASS, implode(', ', $counts));
    }

    private function creativeDeskDivisions(): array
    {
        if (! Schema::hasTable('divisions')) {
            return $this->result('Master divisi Marketing & Creative', self::SKIP, 'Tabel divisions belum tersedia');
        }

        $rows = DB::table('divisions')->get(['code_division', 'name']);
        $hasMarketing = $rows->contains(fn (object $d) => strtoupper((string) $d->code_division) === 'MKT' || str_contains(strtolower((string) $d->name), 'marketing'));
        $hasCreative = $rows->contains(fn (object $d) => in_array(strtoupper((string) $d->code_division), ['CRT', 'CRE'], true) || str_contains(strtolower((string) $d->name), 'creative'));

        $missing = array_filter([
            $hasMarketing ? null : 'Marketing',
            $hasCreative ? null : 'Creative',
        ]);

        if ($missing !== []) {
            return $this->result('Master divisi Marketing & Creative', self::WARN, '[SAV-10] Belum ada divisi: '.implode(', ', $missing));
        }

        return $this->result('Master divisi Marketing & Creative', self::PASS, 'Marketing & Creative tersedia (total '.$rows->count().')');
    }

    private function accountsNullableDivision(): array
    {
        if (! Schema::hasTable('accounts')) {
            return $this->result('Akun dengan division_id NULL', self::SKIP, 'Tabel accounts belum tersedia');
        }

        $count = DB::table('accounts')->whereNull('division_id')->count();

        return $count > 0
            ? $this->result('Akun dengan division_id NULL', self::WARN, "{$count} akun tanpa divisi (tampilkan sebagai unassigned, jangan dipaksa)")
            : $this->result('Akun dengan division_id NULL', self::PASS, '0');
    }

    private function optionsAppsMaster(): array
    {
        if (! Schema::hasTable('options')) {
            return $this->result('Master Apps di options (key=app)', self::SKIP, '[SAV-48] Tabel options belum tersedia');
        }

        $issues = $this->missingTableOrColumns(['options']);
        if ($issues !== []) {
            return $this->result('Master Apps di options (key=app)', self::FAIL, '[SAV-48] '.implode('; ', $issues));
        }

        $active = DB::table('options')->where('key', 'app')->where('archived', 0)->count();
        $archived = DB::table('options')->where('key', 'app')->where('archived', '!=', 0)->count();

        if ($active === 0) {
            return $this->result('Master Apps di options (key=app)', self::WARN, "[SAV-10] 0 Apps aktif (archived={$archived}); Apps tetap mengacu options.id, bukan platforms.id");
        }

        return $this->result('Master Apps di options (key=app)', self::PASS, "{$active} aktif, {$archived} arsip (terpisah dari platforms.id)");
    }

    private function contentsSchema(): array
    {
        if (! Schema::hasTable('contents')) {
            return $this->result('Kolom Dashboard pada contents', self::SKIP, '[SAV-48] Tabel contents belum tersedia');
        }

        $columns = Schema::getColumnListing('contents');
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS['contents'], $columns));
        $editorColumn = $this->contentsEditorColumn($columns);

        if ($editorColumn === null) {
            $missing[] = 'editor_account_id|creative_user_id';
        }

        if ($missing !== []) {
            return $this->result('Kolom Dashboard pada contents', self::FAIL, '[SAV-48] Kolom kurang: '.implode(', ', $missing));
        }

        // CreativeDesk To-Be.sql proposes editor_account_id; As-Is CreaBoard uses creative_user_id.
        return $editorColumn === 'editor_account_id'
            ? $this->result('Kolom Dashboard pada contents', self::PASS, 'Lengkap (editor_account_id To-Be)')
            : $this->result('Kolom Dashboard pada contents', self::WARN, '[SAV-48/SAV-10] Lengkap, tetapi masih memakai creative_user_id (As-Is)');
    }

    private function contentsOptionReferences(): array
    {
        if (! Schema::hasTable('contents') || $this->missingTableOrColumns(['options']) !== []) {
            return $this->result('Referensi options pada contents', self::SKIP, '[SAV-48] Tabel/kolom contents/options belum lengkap');
        }

        $columns = Schema::getColumnListing('contents');
        $map = [
            'app' => 'app',
            'script_status' => 'script',
            'creative_status' => 'creative',
            'qc_status' => 'qc',
            'talent_status' => 'talent',
            'talent_name' => 'talentName',
            'priority' => 'priority',
        ];

        $broken = [];
        foreach ($map as $column => $optionKey) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            $orphans = DB::table('contents')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('options')
                    ->whereColumn('options.id', "contents.{$column}")
                    ->where('options.key', $optionKey))
                ->count();

            if ($orphans > 0) {
                $broken[] = "{$column}: {$orphans}";
            }
        }

        return $broken === []
            ? $this->result('Referensi options pada contents', self::PASS, '0 referensi invalid/orphan')
            : $this->result('Referensi options pada contents', self::FAIL, '[SAV-10] Referensi invalid: '.implode(', ', $broken));
    }

    private function kpiAndCalendarPlanGrain(): array
    {
        if (! Schema::hasTable('kpis') || ! Schema::hasTable('calendar_plans')) {
            return $this->result('Grain unik kpis & calendar_plans', self::SKIP, '[SAV-48] Tabel kpis/calendar_plans belum tersedia');
        }

        $issues = $this->missingTableOrColumns(['kpis', 'calendar_plans']);
        if ($issues !== []) {
            return $this->result('Grain unik kpis & calendar_plans', self::FAIL, '[SAV-48] '.implode('; ', $issues));
        }

        $dupKpis = DB::table('kpis')
            ->select('user_id', 'app', 'type', 'work_date')
            ->groupBy('user_id', 'app', 'type', 'work_date')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $dupPlans = DB::table('calendar_plans')
            ->select('app', 'type', 'date')
            ->groupBy('app', 'type', 'date')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        return ($dupKpis === 0 && $dupPlans === 0)
            ? $this->result('Grain unik kpis & calendar_plans', self::PASS, '0 duplikat grain')
            : $this->result('Grain unik kpis & calendar_plans', self::FAIL, "[SAV-10] Duplikat grain: kpis={$dupKpis}, calendar_plans={$dupPlans}");
    }

    private function kpiCalendarPlanOverlap(): array
    {
        if ($this->missingTableOrColumns(['kpis', 'calendar_plans']) !== []) {
            return $this->result('Deteksi double-counting kpis vs calendar_plans', self::SKIP, '[SAV-48] Tabel/kolom kpis/calendar_plans belum lengkap');
        }

        // Joining calendar_plans directly to kpis multiplies aggregate plan targets when >1 staff KPI shares (app, type, date).
        $overlaps = DB::table('calendar_plans as cp')
            ->join('kpis as k', fn ($join) => $join
                ->on('k.app', '=', 'cp.app')
                ->on('k.type', '=', 'cp.type')
                ->on('k.work_date', '=', 'cp.date'))
            ->select('cp.app', 'cp.type', 'cp.date')
            ->groupBy('cp.app', 'cp.type', 'cp.date')
            ->havingRaw('count(k.id) > 1')
            ->get()
            ->count();

        return $overlaps > 0
            ? $this->result('Deteksi double-counting kpis vs calendar_plans', self::WARN, "{$overlaps} baris calendar_plans punya >1 record kpis (wajib agregasi terpisah)")
            : $this->result('Deteksi double-counting kpis vs calendar_plans', self::PASS, '0 overlap multi-KPI (tetap wajib agregasi terpisah)');
    }

    private function targetLogicalAppReferences(): array
    {
        if ($this->missingTableOrColumns(['options']) !== [] || (! Schema::hasTable('kpis') && ! Schema::hasTable('calendar_plans'))) {
            return $this->result('Relasi logis app di kpis & calendar_plans', self::SKIP, '[SAV-48] Tabel/kolom options/kpis/calendar_plans belum lengkap');
        }

        $broken = [];
        foreach (['kpis', 'calendar_plans'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'app')) {
                continue;
            }

            $orphans = DB::table($table)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('options')
                    ->whereColumn('options.id', "{$table}.app")
                    ->where('options.key', 'app'))
                ->count();

            if ($orphans > 0) {
                $broken[] = "{$table}.app: {$orphans}";
            }
        }

        return $broken === []
            ? $this->result('Relasi logis app di kpis & calendar_plans', self::PASS, '0 app orphan')
            : $this->result('Relasi logis app di kpis & calendar_plans', self::FAIL, '[SAV-10] App tidak ditemukan di options(key=app): '.implode(', ', $broken));
    }

    private function eventsIntegrity(): array
    {
        if (! Schema::hasTable('events') || ! Schema::hasTable('contents')) {
            return $this->result('Integritas events & kolom reason', self::SKIP, '[SAV-48] Tabel events/contents belum tersedia');
        }

        $issues = $this->missingTableOrColumns(['events']);
        if ($issues !== []) {
            return $this->result('Integritas events & kolom reason', self::FAIL, '[SAV-48] '.implode('; ', $issues));
        }

        $orphans = DB::table('events')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('contents')->whereColumn('contents.id', 'events.content_id'))
            ->count();

        if ($orphans > 0) {
            return $this->result('Integritas events & kolom reason', self::FAIL, "[SAV-10] {$orphans} events memiliki content_id orphan");
        }

        return Schema::hasColumn('events', 'reason')
            ? $this->result('Integritas events & kolom reason', self::PASS, '0 orphan, kolom events.reason tersedia')
            : $this->result('Integritas events & kolom reason', self::WARN, '[SAV-48/SAV-42] 0 orphan, tetapi kolom events.reason nullable belum tersedia');
    }

    private function accountReferencesIntegrity(): array
    {
        if (! Schema::hasTable('accounts')) {
            return $this->result('Integritas referensi akun ke accounts.id', self::SKIP, 'Tabel accounts belum tersedia');
        }

        $candidates = [
            ['contents', 'editor_account_id'],
            ['contents', 'creative_user_id'],
            ['contents', 'marketing_user_id'],
            ['contents', 'published_by'],
            ['kpis', 'user_id'],
            ['events', 'user_id'],
        ];

        $checked = 0;
        $orphans = [];

        foreach ($candidates as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $checked++;
            $count = DB::table($table)
                ->whereNotNull($column)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('accounts')->whereColumn('accounts.id', "{$table}.{$column}"))
                ->count();

            if ($count > 0) {
                $orphans[] = "{$table}.{$column}: {$count}";
            }
        }

        if ($checked === 0) {
            return $this->result('Integritas referensi akun ke accounts.id', self::SKIP, '[SAV-10] Belum ada tabel CreativeDesk untuk divalidasi');
        }

        return $orphans === []
            ? $this->result('Integritas referensi akun ke accounts.id', self::PASS, "0 orphan ({$checked} kolom diperiksa)")
            : $this->result('Integritas referensi akun ke accounts.id', self::FAIL, '[SAV-10] Tidak cocok di accounts.id: '.implode(', ', $orphans));
    }

    private function inactiveAccountsWithHistory(): array
    {
        if (! Schema::hasTable('accounts') || ! Schema::hasTable('contents')) {
            return $this->result('Akun nonaktif dengan histori konten', self::SKIP, '[SAV-48] Tabel accounts/contents belum tersedia');
        }

        $editorColumn = $this->contentsEditorColumn(Schema::getColumnListing('contents'));
        if ($editorColumn === null) {
            return $this->result('Akun nonaktif dengan histori konten', self::SKIP, '[SAV-48] Kolom editor di contents belum tersedia');
        }

        $count = DB::table('accounts')
            ->where('is_active', false)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('contents')->whereColumn("contents.{$editorColumn}", 'accounts.id'))
            ->count();

        return $count > 0
            ? $this->result('Akun nonaktif dengan histori konten', self::WARN, "{$count} akun nonaktif punya histori di contents.{$editorColumn} (wajib dipertahankan di laporan historis)")
            : $this->result('Akun nonaktif dengan histori konten', self::PASS, '0');
    }

    private function indexesAndForeignKeys(): array
    {
        if (array_filter(self::CREATIVE_DESK_TABLES, fn (string $table) => ! Schema::hasTable($table)) !== []) {
            return $this->result('Index & aturan FK CreativeDesk', self::SKIP, '[SAV-48] Tabel CreativeDesk belum tersedia');
        }

        if (DB::getDriverName() === 'mysql') {
            $cascades = DB::table('information_schema.REFERENTIAL_CONSTRAINTS as rc')
                ->join('information_schema.KEY_COLUMN_USAGE as k', fn ($join) => $join
                    ->on('k.CONSTRAINT_SCHEMA', '=', 'rc.CONSTRAINT_SCHEMA')
                    ->on('k.CONSTRAINT_NAME', '=', 'rc.CONSTRAINT_NAME')
                    ->on('k.TABLE_NAME', '=', 'rc.TABLE_NAME'))
                ->where('rc.CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->whereIn('rc.TABLE_NAME', self::CREATIVE_DESK_TABLES)
                ->where('rc.DELETE_RULE', 'CASCADE')
                ->get(['rc.TABLE_NAME as table_name', 'k.COLUMN_NAME as column_name'])
                ->filter(fn (object $r) => in_array("{$r->table_name}.{$r->column_name}", ['kpis.user_id', 'events.content_id'], true))
                ->map(fn (object $r) => "{$r->table_name}.{$r->column_name}")
                ->values()
                ->all();

            return $cascades === []
                ? $this->result('Index & aturan FK CreativeDesk', self::PASS, 'Tidak ada CASCADE berisiko pada kpis.user_id / events.content_id')
                : $this->result('Index & aturan FK CreativeDesk', self::WARN, '[SAV-48] Review retensi CASCADE pada: '.implode(', ', $cascades));
        }

        // SQLite cannot inspect MySQL DELETE_RULE metadata from information_schema.
        $contentsIdx = count(DB::select("PRAGMA index_list('contents')"));
        $eventsIdx = count(DB::select("PRAGMA index_list('events')"));

        return $this->result('Index & aturan FK CreativeDesk', self::PASS, "SQLite index: contents={$contentsIdx}, events={$eventsIdx} (FK DELETE_RULE diverifikasi di MySQL)");
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function missingTableOrColumns(array $tables): array
    {
        $issues = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $issues[] = "tabel {$table} tidak ada";

                continue;
            }

            $missing = array_values(array_diff(self::REQUIRED_COLUMNS[$table], Schema::getColumnListing($table)));
            if ($missing !== []) {
                $issues[] = "{$table} kurang kolom: ".implode(', ', $missing);
            }
        }

        return $issues;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function contentsEditorColumn(array $columns): ?string
    {
        foreach (['editor_account_id', 'creative_user_id'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function result(string $check, string $status, string $detail): array
    {
        return ['check' => $check, 'status' => $status, 'detail' => $detail];
    }
}
