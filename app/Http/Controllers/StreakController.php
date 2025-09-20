<?php
// app/Http/Controllers/StreakController.php
namespace App\Http\Controllers;

use App\Models\Partner;
use Illuminate\Http\Request;

class StreakController extends Controller
{
    public function show(Request $r)
    {
        $u = $r->user();

        $pair = Partner::where('status','active')
            ->where(fn($q)=>$q->where('user_a_id',$u->id)->orWhere('user_b_id',$u->id))
            ->select('id','user_a_id','user_b_id','couple_streak_current','couple_streak_longest')
            ->first();

        return response()->json([
            'user' => [
                'current' => (int)$u->streak_current,
                'longest' => (int)$u->streak_longest,
                'today_for' => $u->streak_updated_for_date?->toDateString(),
                'timezone'  => $u->timezone ?: 'UTC',
            ],
            'couple' => $pair ? [
                'pair_id' => (int)$pair->id,
                'current' => (int)$pair->couple_streak_current,
                'longest' => (int)$pair->couple_streak_longest,
            ] : null,
        ]);
    }
}
