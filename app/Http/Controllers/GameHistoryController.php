<?php

namespace App\Http\Controllers;

use App\Models\GameHistory;
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
        ]);

        $user = $request->user();

        // ---- Compute XP on the server (recommended) ----
        // You can tune these weights per game/difficulty.
        $basePerRound = 20;
        $difficultyMul = [
            'Easy'   => 1.0,
            'Medium' => 1.3,
            'Hard'   => 1.6,
        ];
        $rounds  = (int)($v['rounds'] ?? 1);
        $skipped = (int)($v['skipped'] ?? 0);
        $diff    = $v['difficulty'] ?? 'Easy';

        // If client supplied xp_earned, we clamp it; otherwise compute.
        $xpEarned = isset($v['xp_earned'])
            ? max(0, min(5000, (int)$v['xp_earned'])) // trust but cap
            : (int) max(
                0,
                round(($basePerRound * $rounds * ($difficultyMul[$diff] ?? 1.0)) - (5 * $skipped))
            );

        return DB::transaction(function () use ($request, $user, $v, $xpEarned) {
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
            ]);

            // Atomic XP increment (no race conditions)
            $user->increment('xp', $xpEarned);
            $user->refresh(); // get new xp

            return response()->json([
                'history' => $item,
                'user'    => ['id' => $user->id, 'xp' => $user->xp],
            ], 201);
        });
    }
}
