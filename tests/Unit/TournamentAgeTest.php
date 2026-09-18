<?php

namespace Tests\Unit;

use App\Models\Tournament;
use App\Services\Tournaments\TournamentAge;
use Tests\TestCase;

class TournamentAgeTest extends TestCase
{
    public function test_full_years_on_the_calendar_commission_day_not_today_or_event_day(): void
    {
        $tournament = new Tournament;
        $tournament->forceFill(['date_commission' => '2026-09-06 23:59:59', 'date' => '2026-09-07']);
        foreach (['2026-01-01', '2026-09-06', '2027-01-01'] as $today) {
            $this->travelTo(now()->parse($today));
            foreach (['2016-09-05' => 10, '2016-09-06' => 10, '2016-09-07' => 9, '06.09.2016' => 10] as $birth => $expected) {
                $this->assertSame($expected, TournamentAge::onCommissionDay($birth, $tournament));
            }
        }
        $tournament->date_commission = '2026-09-06 00:00:00';
        $this->assertSame(10, TournamentAge::onCommissionDay('2016-09-06', $tournament));
        $this->assertSame('2026-09-06 00:00:00', $tournament->date_commission->toDateTimeString());
    }

    public function test_leap_birthday_and_missing_or_invalid_dates(): void
    {
        $tournament = new Tournament;
        $tournament->forceFill(['date_commission' => '2026-02-28 12:00:00', 'date' => '2026-03-01']);
        $this->assertSame(9, TournamentAge::onCommissionDay('2016-02-29', $tournament));
        $tournament->date_commission = '2026-03-01 00:00:00';
        $this->assertSame(10, TournamentAge::onCommissionDay('2016-02-29', $tournament));
        foreach ([null, '', 'wrong', '2016-02-30', '30.02.2016', '2027-01-01'] as $birth) {
            $this->assertNull(TournamentAge::onCommissionDay($birth, $tournament));
        }
        $tournament->date_commission = null;
        $this->assertSame(10, TournamentAge::onCommissionDay('2016-03-01', $tournament));
        $tournament->date = null;
        $this->assertNull(TournamentAge::onCommissionDay('2016-03-01', $tournament));
    }
}
