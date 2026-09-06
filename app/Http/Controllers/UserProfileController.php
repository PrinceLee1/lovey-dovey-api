<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\GameHistory;
use App\Models\User;
use App\Models\UserPresence;
use App\Support\Xp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserProfileController extends Controller
{
    // GET /users/{id}/profile
    public function show(Request $r, string $id)
    {
        $me = $r->user();
        $target = User::findOrFail($id);

        [$level, $xpInto, $xpNeed] = Xp::breakdown((int) $target->xp);

        // Same "distinct active days this week" calc ProgressController uses
        // for the dashboard's Weekly Streak card, so the number matches
        // whatever the user already sees there.
        $tz = $target->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);
        $from = $now->startOfWeek();
        $to = $now->endOfWeek();
        $activeDays = DB::table('game_histories')
            ->where('user_id', $target->id)
            ->whereBetween('played_at', [$from->utc(), $to->utc()])
            ->pluck('played_at')
            ->map(fn ($ts) => CarbonImmutable::parse($ts, 'UTC')->setTimezone($tz)->toDateString())
            ->unique()
            ->count();

        $gamesPlayed = GameHistory::where('user_id', $target->id)->count();
        $recentGames = GameHistory::where('user_id', $target->id)
            ->orderByDesc('played_at')
            ->limit(5)
            ->get(['game_title', 'xp_earned', 'played_at']);

        $presence = UserPresence::find($target->id);

        $friendshipStatus = 'none';
        $friendshipId = null;

        if ((int) $target->id === (int) $me->id) {
            $friendshipStatus = 'self';
        } else {
            $f = Friendship::where(fn ($q) => $q->where('requester_id', $me->id)->where('addressee_id', $target->id))
                ->orWhere(fn ($q) => $q->where('requester_id', $target->id)->where('addressee_id', $me->id))
                ->first();

            if ($f) {
                $friendshipId = $f->id;
                if ($f->status === 'accepted') {
                    $friendshipStatus = 'accepted';
                } elseif ($f->status === 'blocked') {
                    $friendshipStatus = 'blocked';
                } elseif ($f->status === 'pending') {
                    $friendshipStatus = (int) $f->requester_id === (int) $me->id ? 'pending_sent' : 'pending_received';
                }
            }
        }

        return response()->json([
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'avatar_url' => $target->avatar_url,
                'created_at' => $target->created_at->toIso8601String(),
            ],
            'presence_status' => $presence->status ?? 'offline',
            'stats' => [
                'xp' => (int) $target->xp,
                'level' => $level,
                'weekly_active_days' => $activeDays,
                'weekly_goal_days' => 7,
                'games_played' => $gamesPlayed,
            ],
            'recent_games' => $recentGames,
            'friendship' => [
                'status' => $friendshipStatus,
                'friendship_id' => $friendshipId,
            ],
        ]);
    }
}
