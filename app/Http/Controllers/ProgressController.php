<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Xp;
use Carbon\CarbonImmutable;
class ProgressController extends Controller
{
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

        // Distinct active dates this week (games + daily)
        $gameDays = DB::table('game_histories')
            ->selectRaw("DATE(CONVERT_TZ(played_at, '+00:00', ?)) as d", [$now->getOffsetString()])
            ->where('user_id', $u->id)
            ->whereBetween('played_at', [$from->utc(), $to->utc()])
            ->groupBy('d');

        $dailyDays = DB::table('daily_challenges')
            ->selectRaw("DATE(CONVERT_TZ(completed_at, '+00:00', ?)) as d", [$now->getOffsetString()])
            ->where('user_id', $u->id)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from->utc(), $to->utc()])
            ->groupBy('d');

        $distinct = DB::query()->fromSub(
            $gameDays->union($dailyDays),
            'x'
        )->count();

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
