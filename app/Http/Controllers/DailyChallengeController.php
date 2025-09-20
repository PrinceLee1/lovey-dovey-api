<?php
// app/Http/Controllers/DailyChallengeController.php
namespace App\Http\Controllers;

use App\Models\DailyChallenge;
use App\Models\GameHistory;
use App\Models\Partner;
use App\Models\User;
use App\Support\PartnerGuard;
use App\Support\StreakService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DailyChallengeController extends Controller
{
    // GET /daily-challenge
    public function show(Request $r)
    {
        $u = $r->user();
        $today = CarbonImmutable::now('UTC')->toDateString();

        // active partner?
        $link = Partner::whereIn('status',['active','pending_unpair'])
            ->where(fn($q)=>$q->where('user_a_id',$u->id)->orWhere('user_b_id',$u->id))
            ->first();
        $partnerId = $link && $link->status==='active'
            ? ($link->user_a_id==$u->id ? $link->user_b_id : $link->user_a_id)
            : null;

        // fetch or create row for this user
        $row = DailyChallenge::firstOrNew(['user_id'=>$u->id, 'for_date'=>$today]);

        if (!$row->exists) {
            // generate (duo if partner)
            [$title,$payload,$kind] = $this->generateChallenge($u->id, $partnerId, $today);

            $row->fill([
                'partner_user_id' => $partnerId,
                'kind'            => $kind,
                'title'           => $title,
                'payload'         => $payload,
            ])->save();

            // if duo, mirror for partner so they see the same one
            if ($partnerId) {
                DailyChallenge::firstOrCreate(
                    ['user_id'=>$partnerId, 'for_date'=>$today],
                    [
                        'partner_user_id'=>$u->id,
                        'kind'=>'duo',
                        'title'=>$title,
                        'payload'=>$payload,
                    ]
                );
            }
        }

        return response()->json([
            'challenge'  => [
                'kind' => $row->kind,
                'title'=> $row->title,
                'payload' => $row->payload,
                'status'  => $row->status,
            ],
            'expires_at' => CarbonImmutable::now('UTC')->endOfDay()->toIso8601String(),
        ]);
    }

    // POST /daily-challenge/complete
    public function complete(Request $r)
    {
        $v = $r->validate([
            'success' => 'nullable|boolean',
            'meta'    => 'nullable|array'
        ]);
        $u = $r->user();
        $today = CarbonImmutable::now('UTC')->toDateString();

        $row = DailyChallenge::where('user_id',$u->id)->where('for_date',$today)->firstOrFail();

        if ($row->status === 'completed') {
            return response()->json(['message'=>'Already completed','awarded'=>0], 200);
        }

        // Persist completion
        $row->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $xp = 50;

        // Save to history so it appears in "recently played"
        GameHistory::create([
            'user_id'          => $u->id,
            'partner_user_id'  => $row->kind === 'duo' ? $row->partner_user_id : null,
            'game_id'          => 'daily_' . $row->for_date,
            'game_title'       => 'Daily Challenge',
            'kind'             => 'daily_challenge',
            'category'         => 'Daily',
            'duration_minutes' => $row->payload['duration_minutes'] ?? 5,
            'players'          => $row->kind === 'duo' ? 2 : 1,
            'difficulty'       => $row->payload['difficulty'] ?? 'Easy',
            'rounds'           => 1,
            'skipped'          => 0,
            'xp_earned'        => $xp,
            'meta'             => ['title'=>$row->title, ...($v['meta'] ?? [])],
            'played_at'        => now(),
        ]);

        // XP award
        $u->increment('xp', $xp);
        $u->refresh();
        StreakService::bumpUser($u);

        if ($row->kind === 'duo' && $row->partner_user_id) {
            if ($other = User::find($row->partner_user_id)) {
                StreakService::bumpUser($other);
            }
            $pair = Partner::where('status','active')
                ->where(function($q) use ($u, $row){
                    $a = min($u->id, $row->partner_user_id);
                    $b = max($u->id, $row->partner_user_id);
                    $q->where('user_a_id',$a)->where('user_b_id',$b);
                })->first();
            if ($pair) StreakService::bumpCouple($pair);
        }
        return response()->json(['ok'=>true, 'xp_awarded'=>$xp, 'user'=>['xp'=>$u->xp]]);
    }

    /**
     * Generate the challenge (OpenAI or local template). Uses Redis cache so
     * the same pair/user gets the same content for the day.
     * Returns [title, payload, kind].
     */
    protected function generateChallenge(int $userId, ?int $partnerId, string $date): array
    {
        $isDuo = (bool) $partnerId;
        $kind  = $isDuo ? 'duo' : 'solo';

        // deterministic cache key per day
        $key = $isDuo
            ? "daily:duo:" . min($userId,$partnerId) . ":" . max($userId,$partnerId) . ":$date"
            : "daily:solo:$userId:$date";

        $cached = Cache::get($key);
        if ($cached) return [$cached['title'],$cached['payload'],$kind];

        // ---- Option A: lightweight local generator (no external API) ----
        $banks = $isDuo ? [
            ['title'=>'Two Truths & A Dream', 'desc'=>'Share two truths and one dream you’d love to live together.',
             'steps'=>['Player A goes first','Player B guesses the dream','Swap roles'], 'dur'=>6, 'diff'=>'Easy'],
            ['title'=>'Memory Lane Mini', 'desc'=>'Recall a tiny, happy moment you shared this week.',
             'steps'=>['Pick a moment','Describe 3 details','Say why it mattered'], 'dur'=>5, 'diff'=>'Easy'],
            ['title'=>'Compliment Volley', 'desc'=>'Give each other 3 specific compliments.',
             'steps'=>['A gives 1 compliment','B gives 1 compliment','Repeat twice'], 'dur'=>4, 'diff'=>'Easy'],
        ] : [
            ['title'=>'Gratitude Snapshot', 'desc'=>'Write one thing you’re grateful for today and why.',
             'steps'=>['Open notes','Write 3 sentences','Optional: share with partner later'], 'dur'=>4, 'diff'=>'Easy'],
            ['title'=>'Micro-Adventure Plan', 'desc'=>'Plan a 30-minute mini date for later this week.',
             'steps'=>['Pick a day','Pick an activity','Set a time'], 'dur'=>5, 'diff'=>'Medium'],
            ['title'=>'Kind Message', 'desc'=>'Send your partner a kind or flirty 2-line message.',
             'steps'=>['Open chat','Write two sincere lines','Hit send'], 'dur'=>3, 'diff'=>'Easy'],
        ];

        $pick = $banks[crc32($key) % count($banks)];

        $payload = [
            'description'      => $pick['desc'],
            'steps'            => $pick['steps'],
            'duration_minutes' => $pick['dur'],
            'difficulty'       => $pick['diff'],
        ];
        $title = $pick['title'];

        Cache::put($key, ['title'=>$title,'payload'=>$payload], now()->addDay());

        return [$title,$payload,$kind];
    }
}
