<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\ScheduleEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ScheduleEventSeeder extends Seeder
{
    /**
     * Agendas of every division: title => [days from this Monday to the start, length in days].
     */
    public const SHARED_AGENDAS = [
        'Rapat koordinasi bulanan' => [0, 1],
        'Live Instagram promo akhir bulan' => [3, 1],
        'Libur cuti bersama' => [9, 2],
        'Evaluasi kampanye kuartal' => [15, 3],
    ];

    /**
     * Demo agendas around the current week, so the Timeline has something to show locally.
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

        // One internal agenda per division, visible only to that division and Admin.
        Division::query()->orderBy('id')->get()->each(fn (Division $division, int $index) => ScheduleEvent::create([
            'title' => "Briefing internal {$division->name}",
            'division_id' => $division->id,
            'start_date' => $monday->addDays(1 + $index % 5),
            'is_shared' => false,
        ]));
    }
}
