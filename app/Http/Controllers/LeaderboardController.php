<?php
// app/Http/Controllers/LeaderboardController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class LeaderboardController extends Controller
{
    public function index(Request $request)
    {
        $scope = $request->query('scope', 'all_time'); // all_time|weekly|monthly
        $me    = $request->user();

        // Cache key per scope to avoid heavy queries
        $cacheKeyTop = "lb:top:{$scope}";
        $cacheKeyMe  = "lb:me:{$scope}:{$me->id}";

        $top = Cache::remember($cacheKeyTop, 60, function () use ($scope) {
            return $this->buildLeaderboard($scope, 10);
        });

        $mine = Cache::remember($cacheKeyMe, 60, function () use ($scope, $me) {
            return $this->myRank($scope, $me->id);
        });

        return response()->json([
            'scope' => $scope,
            'top'   => $top,        // [{rank, pair_id, duo_name, xp, users:[{id,name},{...}]}]
            'me'    => $mine,       // {rank, pair_id, duo_name, xp} | null if not paired
        ]);
    }

    protected function windowDates(string $scope): array
    {
        $now = now()->startOfDay();
        return match ($scope) {
            'weekly'  => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default   => [null, null], // all_time
        };
    }

    protected function buildLeaderboard(string $scope, int $limit): array
    {
        [$from, $to] = $this->windowDates($scope);

        if ($scope === 'all_time') {
            // Sum of current users.xp for active pairs
            $rows = DB::select(<<<SQL
                WITH pairs AS (
                  SELECT p.id as pair_id,
                         p.user_a_id, p.user_b_id,
                         (u1.xp + u2.xp) AS xp,
                         CONCAT(u1.name, ' & ', u2.name) AS duo_name
                  FROM partners p
                  JOIN users u1 ON u1.id = p.user_a_id
                  JOIN users u2 ON u2.id = p.user_b_id
                  WHERE p.status = 'active'
                ),
                ranked AS (
                  SELECT pair_id, user_a_id, user_b_id, xp, duo_name,
                         DENSE_RANK() OVER (ORDER BY xp DESC) AS rnk
                  FROM pairs
                )
                SELECT pair_id, user_a_id, user_b_id, xp, duo_name, rnk
                FROM ranked
                ORDER BY rnk, duo_name
                LIMIT ?
            SQL, [$limit]);
        } else {
            // Windowed: sum xp from game_histories between dates for each active pair
            $rows = DB::select(<<<SQL
                WITH base AS (
                  SELECT p.id AS pair_id, p.user_a_id, p.user_b_id
                  FROM partners p
                  WHERE p.status = 'active'
                ),
                summed AS (
                  SELECT b.pair_id, b.user_a_id, b.user_b_id,
                         COALESCE(SUM(gh.xp_earned),0) AS xp
                  FROM base b
                  LEFT JOIN game_histories gh
                    ON gh.user_id IN (b.user_a_id, b.user_b_id)
                   AND gh.played_at BETWEEN ? AND ?
                  GROUP BY b.pair_id, b.user_a_id, b.user_b_id
                ),
                named AS (
                  SELECT s.pair_id, s.user_a_id, s.user_b_id, s.xp,
                         CONCAT(u1.name, ' & ', u2.name) AS duo_name
                  FROM summed s
                  JOIN users u1 ON u1.id = s.user_a_id
                  JOIN users u2 ON u2.id = s.user_b_id
                ),
                ranked AS (
                  SELECT pair_id, user_a_id, user_b_id, xp, duo_name,
                         DENSE_RANK() OVER (ORDER BY xp DESC) AS rnk
                  FROM named
                )
                SELECT pair_id, user_a_id, user_b_id, xp, duo_name, rnk
                FROM ranked
                ORDER BY rnk, duo_name
                LIMIT ?
            SQL, [$from, $to, $limit]);
        }

        return array_map(function ($r) {
            return [
                'rank'     => (int)$r->rnk,
                'pair_id'  => (int)$r->pair_id,
                'duo_name' => $r->duo_name,
                'xp'       => (int)$r->xp,
                'users'    => [
                    ['id'=>(int)$r->user_a_id],
                    ['id'=>(int)$r->user_b_id],
                ],
            ];
        }, $rows);
    }

    protected function myRank(string $scope, int $userId): ?array
    {
        // find my active pair (if any)
        $pair = DB::table('partners')
            ->where('status','active')
            ->where(function($q) use ($userId){
                $q->where('user_a_id',$userId)->orWhere('user_b_id',$userId);
            })->first();

        if (!$pair) return null;

        [$from,$to] = $this->windowDates($scope);

        if ($scope === 'all_time') {
            $row = DB::selectOne(<<<SQL
                WITH pairs AS (
                  SELECT p.id as pair_id,
                         p.user_a_id, p.user_b_id,
                         (u1.xp + u2.xp) AS xp,
                         CONCAT(u1.name, ' & ', u2.name) AS duo_name
                  FROM partners p
                  JOIN users u1 ON u1.id = p.user_a_id
                  JOIN users u2 ON u2.id = p.user_b_id
                  WHERE p.status = 'active'
                ),
                ranked AS (
                  SELECT pair_id, user_a_id, user_b_id, xp, duo_name,
                         DENSE_RANK() OVER (ORDER BY xp DESC) AS rnk
                  FROM pairs
                )
                SELECT pair_id, duo_name, xp, rnk
                FROM ranked
                WHERE pair_id = ?
                LIMIT 1
            SQL, [$pair->id]);
        } else {
            $row = DB::selectOne(<<<SQL
                WITH base AS (
                  SELECT p.id AS pair_id, p.user_a_id, p.user_b_id
                  FROM partners p
                  WHERE p.status = 'active'
                ),
                summed AS (
                  SELECT b.pair_id,
                         COALESCE(SUM(gh.xp_earned),0) AS xp
                  FROM base b
                  LEFT JOIN game_histories gh
                    ON gh.user_id IN (b.user_a_id, b.user_b_id)
                   AND gh.played_at BETWEEN ? AND ?
                  GROUP BY b.pair_id
                ),
                named AS (
                  SELECT s.pair_id, s.xp,
                         CONCAT(u1.name, ' & ', u2.name) AS duo_name
                  FROM summed s
                  JOIN partners p ON p.id = s.pair_id
                  JOIN users u1 ON u1.id = p.user_a_id
                  JOIN users u2 ON u2.id = p.user_b_id
                ),
                ranked AS (
                  SELECT pair_id, duo_name, xp,
                         DENSE_RANK() OVER (ORDER BY xp DESC) AS rnk
                  FROM named
                )
                SELECT pair_id, duo_name, xp, rnk
                FROM ranked
                WHERE pair_id = ?
                LIMIT 1
            SQL, [$from, $to, $pair->id]);
        }

        if (!$row) return null;

        return [
            'rank'     => (int)$row->rnk,
            'pair_id'  => (int)$row->pair_id,
            'duo_name' => $row->duo_name,
            'xp'       => (int)$row->xp,
        ];
    }
}
