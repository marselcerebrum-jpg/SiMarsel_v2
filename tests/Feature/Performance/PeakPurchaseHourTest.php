<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX-12-BE-01: Jam Ramai Pembelian, the 24 WIB payment hours of /performance/purchase-times (`hourly`), with the
 * day of the week applied to both periods and the difference between hours after the cutoff and empty hours.
 */
class PeakPurchaseHourTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/purchase-times';

    /** Wednesday 11 Nov 2026: last 7 days = Wed 04 - Tue 10 Nov, comparison Wed 28 Okt - Tue 03 Nov. */
    private const NOW = '2026-11-11T14:30:00+07:00';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);

        $this->travelTo(CarbonImmutable::parse(self::NOW));
    }

    public function test_ac01_a_payment_at_19_20_fills_slot_19_with_its_count_and_revenue(): void
    {
        $this->lastSevenDaysCoverage();
        $this->payment('2026-11-06 08:00:00', '2026-11-06 19:20:00', 250000);

        $hourly = $this->hourly('preset=last_7_days');

        $this->assertCount(24, $hourly['slots']);
        $this->assertSame(range(0, 23), array_column($hourly['slots'], 'hour'));
        $this->assertSame('19.00–19.59', $hourly['slots'][19]['label']);
        $this->assertSame(1, $hourly['slots'][19]['active']['success_payments']);
        $this->assertSame(250000.0, (float) $hourly['slots'][19]['active']['revenue']);
        $this->assertSame('complete', $hourly['slots'][19]['active']['availability']);
        $this->assertSame(0, $hourly['slots'][8]['active']['success_payments']);
        $this->assertSame('Asia/Jakarta', $hourly['timezone']);
        $this->assertSame(1, $hourly['active']['totals']['success_payments']);
        $this->assertSame(7, $hourly['active']['days']);
    }

    public function test_ac02_all_days_is_the_default_and_a_one_day_period_has_no_day_choice(): void
    {
        $this->lastSevenDaysCoverage();
        $this->coverage('2026-11-11', '2026-11-11', '2026-11-11 14:00:00');

        $week = $this->hourly('preset=last_7_days');

        $this->assertTrue($week['day_selectable']);
        $this->assertNull($week['day_of_week']);
        $this->assertSame('Semua Hari', $week['day_label']);
        $this->assertSame(range(1, 7), array_column($week['day_options'], 'value'));
        $this->assertSame('Jumat', $week['day_options'][4]['label']);

        // The day is ignored for one day, so a day still selected on the page does not break the chart.
        $today = $this->hourly('preset=today&day_of_week=5');

        $this->assertFalse($today['day_selectable']);
        $this->assertSame([], $today['day_options']);
        $this->assertNull($today['day_of_week']);
        $this->assertSame(1, $today['active']['days']);
    }

    public function test_ac02_friday_applies_to_both_periods(): void
    {
        $this->lastSevenDaysCoverage();
        $this->payment('2026-11-06 10:00:00', '2026-11-06 19:20:00', 100000);  // Friday, active
        $this->payment('2026-11-07 10:00:00', '2026-11-07 19:40:00', 100000);  // Saturday, active
        $this->payment('2026-10-30 10:00:00', '2026-10-30 20:10:00', 200000);  // Friday, comparison
        $this->payment('2026-10-31 10:00:00', '2026-10-31 20:30:00', 200000);  // Saturday, comparison

        $all = $this->hourly('preset=last_7_days');
        $friday = $this->hourly('preset=last_7_days&day_of_week=5');

        $this->assertSame(2, $all['slots'][19]['active']['success_payments']);
        $this->assertSame(2, $all['slots'][20]['comparison']['success_payments']);

        $this->assertSame(5, $friday['day_of_week']);
        $this->assertSame('Jumat', $friday['day_label']);
        $this->assertSame(1, $friday['active']['days']);
        $this->assertSame(1, $friday['comparison']['days']);
        $this->assertSame(1, $friday['slots'][19]['active']['success_payments']);
        $this->assertSame(1, $friday['slots'][20]['comparison']['success_payments']);
        $this->assertSame(200000.0, (float) $friday['slots'][20]['comparison']['revenue']);
        $this->assertSame(1, $friday['active']['totals']['success_payments']);
        $this->assertSame(1, $friday['comparison']['totals']['success_payments']);
    }

    public function test_ac02_hours_after_the_cutoff_are_unavailable_on_both_sides_while_complete_empty_hours_are_zero(): void
    {
        $this->coverage('2026-11-11', '2026-11-11', '2026-11-11 14:00:00');
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-10 14:00:00');
        $this->payment('2026-11-11 08:00:00', '2026-11-11 09:15:00', 100000);
        $this->payment('2026-11-10 08:00:00', '2026-11-10 09:45:00', 100000);
        // Paid after the equivalent hour of the comparison day: outside its cutoff.
        $this->payment('2026-11-10 08:00:00', '2026-11-10 16:00:00', 100000);

        $hourly = $this->hourly('preset=today');

        foreach (['active', 'comparison'] as $side) {
            $this->assertSame('complete', $hourly['slots'][11][$side]['availability']);
            $this->assertSame(0, $hourly['slots'][11][$side]['success_payments']);
            $this->assertSame(1, $hourly['slots'][9][$side]['success_payments']);
            $this->assertSame('unavailable', $hourly['slots'][14][$side]['availability']);
            $this->assertNull($hourly['slots'][14][$side]['success_payments']);
            $this->assertNull($hourly['slots'][16][$side]['revenue']);
            $this->assertSame('partial', $hourly[$side]['availability']);
            $this->assertSame(1, $hourly[$side]['totals']['success_payments']);
        }

        $this->assertSame('2026-11-11T14:00:00+07:00', $hourly['active']['as_of']);
        $this->assertSame('2026-11-10T14:00:00+07:00', $hourly['comparison']['as_of']);
    }

    public function test_ac03_one_group_buyer_row_counts_while_members_claims_failed_and_payments_without_a_date_or_time_do_not(): void
    {
        $this->lastSevenDaysCoverage();
        $group = $this->payment('2026-11-06 08:00:00', '2026-11-06 19:20:00', 300000, id: 'GRP-1');
        $this->payment('2026-11-06 08:00:00', '2026-11-06 19:20:00', 0, refId: 'GRP-1');
        $this->payment('2026-11-06 08:00:00', '2026-11-06 19:20:00', 0, refId: 'GRP-1');
        $this->payment('2026-11-06 08:00:00', '2026-11-06 19:25:00', 0, claim: true);
        $this->payment('2026-11-06 08:00:00', '2026-11-06 19:30:00', 90000, status: MarkazTransactionStatus::Failed);
        $this->payment('2026-11-06 08:00:00', null, 80000);
        $this->payment('2026-11-06 08:00:00', '2026-11-06 00:00:00', 70000)->forceFill(['paid_time' => null])->save();

        $hourly = $this->hourly('preset=last_7_days');

        $this->assertSame(1, $hourly['slots'][19]['active']['success_payments']);
        $this->assertSame(300000.0, (float) $hourly['slots'][19]['active']['revenue']);
        $this->assertSame(0, $hourly['slots'][0]['active']['success_payments']);
        $this->assertSame(1, $hourly['active']['totals']['success_payments']);

        $group->forceFill(['paid_time' => '20:20:00'])->save();
        $moved = $this->hourly('preset=last_7_days');

        $this->assertSame(0, $moved['slots'][19]['active']['success_payments']);
        $this->assertSame(1, $moved['slots'][20]['active']['success_payments']);
        $this->assertSame(300000.0, (float) $moved['slots'][20]['active']['revenue']);

        $group->forceFill(['effective_status' => MarkazTransactionStatus::Failed->value])->save();
        $failed = $this->hourly('preset=last_7_days');

        $this->assertSame(0, $failed['slots'][20]['active']['success_payments']);
        $this->assertSame(0, $failed['active']['totals']['success_payments']);
    }

    public function test_a_comparison_without_the_chosen_day_is_empty_with_a_message(): void
    {
        // Mon 02 - Tue 03 Nov, compared with Sat 31 Okt - Sun 01 Nov.
        $this->coverage('2026-11-02', '2026-11-03', '2026-11-04 00:00:00');
        $this->coverage('2026-10-31', '2026-11-01', '2026-11-02 00:00:00');

        $hourly = $this->hourly('preset=date_range&date_from=2026-11-02&date_to=2026-11-03&day_of_week=1');

        $this->assertSame([1, 2], array_column($hourly['day_options'], 'value'));
        $this->assertSame(1, $hourly['active']['days']);
        $this->assertNull($hourly['comparison']);
        $this->assertSame('Tidak ada hari yang sama di periode pembanding.', $hourly['comparison_message']);
        $this->assertNull($hourly['slots'][0]['comparison']);
    }

    public function test_unknown_coverage_gives_unavailable_hours_instead_of_zero(): void
    {
        $this->payment('2026-11-06 08:00:00', '2026-11-06 19:20:00', 100000);

        $hourly = $this->hourly('preset=last_7_days');

        $this->assertSame('unavailable', $hourly['active']['availability']);
        $this->assertNull($hourly['active']['totals']['success_payments']);
        $this->assertNull($hourly['slots'][19]['active']['success_payments']);
    }

    public function test_the_day_must_be_a_day_of_the_active_period(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?preset=date_range&date_from=2026-11-02&date_to=2026-11-03&day_of_week=5')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day_of_week' => 'Hari yang dipilih tidak ada dalam periode aktif.']);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?preset=last_7_days&day_of_week=8')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day_of_week' => 'Hari harus di antara 1 dan 7.']);
    }

    public function test_the_legacy_filter_keeps_the_time_ranges_without_hourly(): void
    {
        $this->payment('2026-11-06 08:00:00', '2026-11-06 19:20:00', 100000);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-11-01&date_to=2026-11-10')
            ->assertOk()
            ->assertJsonPath('data.hourly', null)
            ->assertJsonPath('data.time_ranges.4.key', 'night')
            ->assertJsonPath('data.time_ranges.4.transactions', 1);
    }

    public function test_guests_cannot_read_the_peak_hours(): void
    {
        $this->getJson(self::URL.'?preset=last_7_days')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function hourly(string $query): array
    {
        return $this->actingAs($this->manager)
            ->getJson(self::URL.'?platforms[]=app-a&'.$query)
            ->assertOk()
            ->json('data.hourly');
    }

    private function lastSevenDaysCoverage(): void
    {
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');
    }

    private function payment(
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?string $refId = null,
        ?string $id = null,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->forPlatform($this->platform)->create([
            ...($id === null ? [] : ['markaz_transaction_id' => $id]),
            'markaz_ref_id' => $refId,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status?->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $netNominal + 2000,
            'admin_fee' => 2000,
            'net_nominal' => $netNominal,
        ]);
    }

    private function coverage(string $from, string $to, string $cutoffAt): void
    {
        DashboardSnapshot::factory()->for($this->platform)->create([
            'date_from' => $from,
            'date_to' => $to,
            'cutoff_at' => $cutoffAt,
            'is_complete' => true,
            'payload' => [],
            'recorded_at' => $cutoffAt,
        ]);
    }
}
