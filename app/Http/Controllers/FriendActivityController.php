<?php

namespace App\Http\Controllers;

use App\Models\FriendActivity;
use App\Models\Friendship;
use Illuminate\Http\Request;

class FriendActivityController extends Controller
{
    // GET /friends/activity
    public function index(Request $r)
    {
        $me = $r->user();

        $friendIds = Friendship::forUser($me->id)
            ->where('status', 'accepted')
            ->get()
            ->map(fn ($f) => (int) $f->requester_id === (int) $me->id ? $f->addressee_id : $f->requester_id);

        $activities = FriendActivity::with('actor:id,name,avatar_url')
            ->whereIn('actor_id', $friendIds)
            ->latest('created_at')
            ->limit(20)
            ->get();

        return response()->json(['activities' => $activities]);
    }
}
