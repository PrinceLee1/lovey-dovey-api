<?php

namespace App\Http\Controllers;

use App\Models\GameHistory;
use App\Models\Partner;
use App\Models\User;
use App\Support\StreakService;
use DB;
use Illuminate\Http\Request;

class GameHistoryController extends Controller
{
        public function index(Request $request)
    {
        $limit = min(50, max(1, (int) $request->query('limit', 10)));
        $items = GameHistory::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('played_at')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'game_id'          => 'required|string|max:50',
            'game_title'       => 'required|string|max:200',
            'kind'             => 'required|string|max:50',
            'category'         => 'required|string|max:50',
            'duration_minutes' => 'nullable|integer|min:0|max:600',
            'players'          => 'nullable|integer|min:1|max:32',
            'difficulty'       => 'nullable|string|max:20',
            'rounds'           => 'nullable|integer|min:0|max:1000',
            'skipped'          => 'nullable|integer|min:0|max:1000',
            'xp_earned'        => 'nullable|integer|min:0|max:100000',
            'meta'             => 'nullable|array',
            'played_at'        => 'nullable|date',
            'with_partner'     => 'nullable|boolean', // only for group games
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // ---- Resolve active partner (if any) ----
        $active = Partner::where('status', 'active')
            ->where(function ($q) use ($user) {
                $q->where('user_a_id', $user->id)
                ->orWhere('user_b_id', $user->id);
            })->first();

        $activePartnerId = $active
            ? ($active->user_a_id === $user->id ? $active->user_b_id : $active->user_a_id)
            : null;

        // Decide whether to link this history to the partner
        $players = (int)($v['players'] ?? 2);
        $withPartnerFlag = array_key_exists('with_partner', $v) ? (bool)$v['with_partner'] : null;
        $shouldLinkToPartner = $activePartnerId !== null && ($players <= 2 || $withPartnerFlag === true);

        // ---- Compute XP on the server ----
        $basePerRound = 20;
        $difficultyMul = ['Easy'=>1.0, 'Medium'=>1.3, 'Hard'=>1.6];
        $rounds  = (int)($v['rounds'] ?? 1);
        $skipped = (int)($v['skipped'] ?? 0);
        $diff    = $v['difficulty'] ?? 'Easy';

        $xpEarned = isset($v['xp_earned'])
            ? max(0, min(5000, (int)$v['xp_earned']))
            : (int) max(0, round(($basePerRound * $rounds * ($difficultyMul[$diff] ?? 1.0)) - (5 * $skipped)));

        return DB::transaction(function () use ($user, $v, $xpEarned, $shouldLinkToPartner, $activePartnerId) {

            // 1) Create row for the current user
            $item = GameHistory::create([
                'user_id'          => $user->id,
                'game_id'          => $v['game_id'],
                'game_title'       => $v['game_title'],
                'kind'             => $v['kind'],
                'category'         => $v['category'],
                'duration_minutes' => $v['duration_minutes'] ?? 0,
                'players'          => $v['players'] ?? 2,
                'difficulty'       => $v['difficulty'] ?? null,
                'rounds'           => $v['rounds'] ?? 0,
                'skipped'          => $v['skipped'] ?? 0,
                'xp_earned'        => $xpEarned,
                'meta'             => $v['meta'] ?? null,
                'played_at'        => $v['played_at'] ?? now(),
                'partner_user_id'  => $shouldLinkToPartner ? $activePartnerId : null,
            ]);

            // 2) XP + user streak
            $user->increment('xp', $xpEarned);
            $user->refresh();
            StreakService::bumpUser($user);

            // 3) Partner streaks and optional mirror
            $partnerIdForMirror = $item->partner_user_id; // authoritative partner id from the saved row

            if ($partnerIdForMirror) {
                if ($p = User::find($partnerIdForMirror)) {
                    StreakService::bumpUser($p);
                }

                $pair = Partner::where('status','active')
                    ->where(function($q) use ($user, $partnerIdForMirror){
                        $q->where('user_a_id', min($user->id, $partnerIdForMirror))
                        ->where('user_b_id', max($user->id, $partnerIdForMirror));
                    })->first();

                if ($pair) {
                    StreakService::bumpCouple($pair);
                }

                // Mirror ONLY when we truly have a partner id
                GameHistory::create([
                    'user_id'          => $partnerIdForMirror,
                    'game_id'          => $v['game_id'],
                    'game_title'       => $v['game_title'],
                    'kind'             => $v['kind'],
                    'category'         => $v['category'],
                    'duration_minutes' => $v['duration_minutes'] ?? 0,
                    'players'          => $v['players'] ?? 2,
                    'difficulty'       => $v['difficulty'] ?? null,
                    'rounds'           => $v['rounds'] ?? 0,
                    'skipped'          => $v['skipped'] ?? 0,
                    'xp_earned'        => $xpEarned, // or tune differently
                    'meta'             => $v['meta'] ?? null,
                    'played_at'        => $v['played_at'] ?? now(),
                    'partner_user_id'  => $user->id, // mirror points back to me
                ]);
            }

            return response()->json([
                'history' => $item,
                'user'    => ['id' => $user->id, 'xp' => $user->xp],
            ], 201);
        });
    }
}
