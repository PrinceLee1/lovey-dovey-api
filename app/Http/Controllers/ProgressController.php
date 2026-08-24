<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\WeeklySummaryService;
use App\Support\Xp;
use Carbon\CarbonImmutable;
class ProgressController extends Controller
{
    public function weeklySummary(Request $r)
    {
        return response()->json(WeeklySummaryService::build($r->user()));
    }

    public function show(Request $r)
    {
        $u = $r->user();
        $tz = $u->timezone ?: 'UTC';

        // XP → level breakdown
        [$level, $xpInto, $xpNeed] = Xp::breakdown((int)$u->xp);
        $xpPercent = $xpNeed > 0 ? round(($xpInto / $xpNeed) * 100) : 100;

        // Weekly window (user timezone)
        $now  = CarbonImmutable::now($tz);
        $from = $now->startOfWeek(); // Monday
        $to   = $now->endOfWeek();

        // Distinct active dates this week (games + daily), converted to the
        // user's timezone in PHP rather than via CONVERT_TZ — that's a
        // MySQL-only function and breaks on SQLite (dev/tests), and the
        // window is small enough (one week) that pulling raw timestamps is
        // cheap.
        $gameTimestamps = DB::table('game_histories')
            ->where('user_id', $u->id)
            ->whereBetween('played_at', [$from->utc(), $to->utc()])
            ->pluck('played_at');

        $dailyTimestamps = DB::table('daily_challenges')
            ->where('user_id', $u->id)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from->utc(), $to->utc()])
            ->pluck('completed_at');

        $distinct = $gameTimestamps->concat($dailyTimestamps)
            ->map(fn ($ts) => CarbonImmutable::parse($ts, 'UTC')->setTimezone($tz)->toDateString())
            ->unique()
            ->count();

        $weekGoal = 7;
        $streakPercent = min(100, (int) round(($distinct / $weekGoal) * 100));

        return response()->json([
            'xp' => [
                'total'      => (int)$u->xp,
                'level'      => $level,
                'into_level' => $xpInto,
                'to_next'    => $xpNeed,
                'percent'    => $xpPercent, // 0..100
            ],
            'weekly' => [
                'active_days' => $distinct, // 0..7
                'goal_days'   => $weekGoal,
                'percent'     => $streakPercent,
                'week_start'  => $from->toIso8601String(),
                'week_end'    => $to->toIso8601String(),
            ],
        ]);
    }
}
