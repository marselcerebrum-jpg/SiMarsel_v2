<?php

namespace Tests\Feature\Web;

use App\Models\Account;
use App\Models\Platform;
use App\Support\Period\PeriodCoverageSource;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InMemoryPeriodCoverage;
use Tests\TestCase;

/**
 * The global Dashboard filter (FIX-04-FE-01) sends its query to the performance endpoints and reads
 * `data.filter.period` from the summary response. These requests use the exact query the frontend builds.
 */
class DashboardFilterContractTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY = '/api/performance/summary';

    private Account $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:00:00+07:00'));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);

        $this->app->instance(PeriodCoverageSource::class, (new InMemoryPeriodCoverage)
            ->record('2026-11-11', '2026-11-11', '2026-11-11T14:00:00+07:00')
            ->record('2026-11-10', '2026-11-10', '2026-11-10T14:00:00+07:00'));
    }

    public function test_the_summary_returns_every_period_field_the_filter_reads(): void
    {
        $period = $this->actingAs($this->manager)
            ->getJson(self::SUMMARY.'?platforms%5B%5D=app-a&preset=today')
            ->assertOk()
            ->assertJsonStructure(['data' => ['filter' => ['period' => [
                'comparison_mode',
                'active' => ['date_from', 'date_to', 'start_at', 'end_at', 'days', 'availability', 'cutoff_at', 'state_basis'],
                'comparison' => ['date_from', 'date_to', 'start_at', 'end_at', 'days', 'availability', 'cutoff_at', 'state_basis'],
                'change_basis' => ['is_trimmed', 'active', 'comparison'],
                'durations_differ',
            ]]]])
            ->json('data.filter.period');

        $this->assertSame(['partial', '2026-11-11T14:00:00+07:00'], [$period['active']['availability'], $period['active']['cutoff_at']]);
        $this->assertSame(['at_cutoff', '2026-11-10T14:00:00+07:00'], [$period['comparison']['state_basis'], $period['comparison']['cutoff_at']]);
    }

    public function test_a_manual_comparison_keeps_the_active_period(): void
    {
        $this->actingAs($this->manager);
        $query = self::SUMMARY.'?preset=date_range&date_from=2026-11-05&date_to=2026-11-11';

        $auto = $this->getJson($query)->assertOk()->json('data.filter.period');
        $custom = $this->getJson($query.'&comparison=custom&comparison_from=2026-10-01&comparison_to=2026-10-10')->assertOk()->json('data.filter.period');

        $this->assertSame($auto['active'], $custom['active']);
        $this->assertSame(['custom', true], [$custom['comparison_mode'], $custom['durations_differ']]);
        $this->assertSame(['2026-10-01', '2026-10-10'], [$custom['comparison']['date_from'], $custom['comparison']['date_to']]);
    }

    public function test_a_reversed_range_is_rejected_on_the_end_field(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::SUMMARY.'?preset=date_range&date_from=2026-11-11&date_to=2026-11-05')
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['date_to' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);
    }
}
