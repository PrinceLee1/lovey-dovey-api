<?php

namespace App\Support;

use App\Models\GameHistory;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates "what did this user do this week" across the two separate
 * data sources the app tracks activity in:
 *  - game_histories: couple games (GameHistoryController::store), tagged
 *    with partner_user_id when played with an active partner.
 *  - lobby_game_sessions: party/lobby games, which aren't linked to a
 *    specific player, only to the lobby — membership (lobby_members) is
 *    used as a best-effort proxy for "this user played this session".
 * Used by both the GET /me/weekly-summary endpoint (dashboard chart) and
 * the scheduled weekly digest email, so the two never drift apart.
 */
class WeeklySummaryService
{
    public static function build(User $user, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $to = $to ?? CarbonImmutable::now();
        $from = $from ?? $to->subDays(7);

        $coupleGames = GameHistory::where('user_id', $user->id)
            ->whereBetween('played_at', [$from, $to])
            ->get(['partner_user_id', 'xp_earned', 'played_at']);

        $gamesWithPartner = $coupleGames->whereNotNull('partner_user_id')->count();
        $gamesTotal = $coupleGames->count();
        $xpEarned = (int) $coupleGames->sum('xp_earned');

        $lobbySessions = DB::table('lobby_game_sessions')
            ->join('lobby_members', 'lobby_members.lobby_id', '=', 'lobby_game_sessions.lobby_id')
            ->where('lobby_members.user_id', $user->id)
            ->where('lobby_game_sessions.status', 'ended')
            ->whereBetween('lobby_game_sessions.ended_at', [$from, $to])
            ->get(['lobby_game_sessions.ended_at']);

        $lobbyGames = $lobbySessions->count();

        $partner = $user->activePartner();
        $couplePair = $partner
            ? Partner::where('status', 'active')
                ->where('user_a_id', min($user->id, $partner->id))
                ->where('user_b_id', max($user->id, $partner->id))
                ->first()
            : null;

        // Daily breakdown for the dashboard chart — merge both sources by day.
        $daily = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $to->subDays($i)->toDateString();
            $daily[$day] = 0;
        }
        foreach ($coupleGames as $g) {
            $day = CarbonImmutable::parse($g->played_at)->toDateString();
            if (array_key_exists($day, $daily)) $daily[$day]++;
        }
        foreach ($lobbySessions as $s) {
            $day = CarbonImmutable::parse($s->ended_at)->toDateString();
            if (array_key_exists($day, $daily)) $daily[$day]++;
        }

        return [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'games_with_partner' => $gamesWithPartner,
            'games_total' => $gamesTotal,
            'lobby_games' => $lobbyGames,
            'xp_earned' => $xpEarned,
            'current_streak' => (int) $user->streak_current,
            'longest_streak' => (int) $user->streak_longest,
            'couple_streak_current' => $couplePair ? (int) $couplePair->couple_streak_current : null,
            'couple_streak_longest' => $couplePair ? (int) $couplePair->couple_streak_longest : null,
            'partner_name' => $partner?->name,
            'daily' => collect($daily)->map(fn ($count, $date) => ['date' => $date, 'games' => $count])->values(),
        ];
    }
}
