<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\ScheduleEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ScheduleEventSeeder extends Seeder
{
    /**
     * Agendas shared with everyone: title => [days from this Monday to the start, length in days].
     */
    public const SHARED_AGENDAS = [
        'Rapat koordinasi bulanan' => [0, 1],
        'Live Instagram promo akhir bulan' => [3, 1],
        'Libur cuti bersama' => [9, 2],
        'Evaluasi kampanye kuartal' => [15, 3],
    ];

    /**
     * Demo agendas around the current week, so the Timeline has something to show locally: the shared ones,
     * an internal briefing per division, one internal agenda for several divisions, and one for Admin only.
     */
    public function run(): void
    {
        if (ScheduleEvent::query()->exists()) {
            return;
        }

        $monday = CarbonImmutable::today()->startOfWeek();

        foreach (self::SHARED_AGENDAS as $title => [$offset, $days]) {
            ScheduleEvent::create([
                'title' => $title,
                'start_date' => $monday->addDays($offset),
                'end_date' => $days > 1 ? $monday->addDays($offset + $days - 1) : null,
                'is_shared' => true,
            ]);
        }

        $divisions = Division::query()->orderBy('id')->get();

        $divisions->each(fn (Division $division, int $index) => $this->internal(
            "Briefing internal {$division->name}",
            $monday->addDays(1 + $index % 5),
            $division,
            [$division->id],
        ));

        if ($divisions->count() >= 2) {
            [$owner, $target] = [$divisions[0], $divisions[1]];

            $this->internal("Sinkronisasi {$owner->name} dan {$target->name}", $monday->addDays(2), $owner, [$owner->id, $target->id]);
        }

        $this->internal('Rapat pimpinan', $monday->addDays(4), null, []);
    }

    /**
     * @param  array<int, int>  $targetDivisionIds
     */
    private function internal(string $title, CarbonImmutable $date, ?Division $owner, array $targetDivisionIds): void
    {
        ScheduleEvent::create([
            'title' => $title,
            'owner_division_id' => $owner?->id,
            'start_date' => $date,
            'is_shared' => false,
        ])->targetDivisions()->attach($targetDivisionIds);
    }
}
