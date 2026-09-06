<?php

namespace App\Http\Controllers;

use App\Events\UserPresenceUpdated;
use App\Models\Friendship;
use App\Models\UserPresence;
use App\Support\Broadcasting;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    // POST /presence/update
    public function update(Request $r)
    {
        $me = $r->user();

        $v = $r->validate([
            'status' => 'required|in:online,idle,in_game,offline',
            'current_lobby_id' => 'nullable|integer|exists:lobbies,id',
        ]);

        $presence = UserPresence::updateOrCreate(
            ['user_id' => $me->id],
            [
                'status' => $v['status'],
                'current_lobby_id' => $v['current_lobby_id'] ?? null,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]
        );

        Broadcasting::fire(new UserPresenceUpdated($me->id, $presence->status, $presence->current_lobby_id));

        return response()->json(['presence' => $presence]);
    }

    // GET /presence/friends
    public function friends(Request $r)
    {
        $me = $r->user();

        $friendships = Friendship::forUser($me->id)->where('status', 'accepted')->get();
        $friendIds = $friendships->map(fn ($f) => (int) $f->requester_id === (int) $me->id ? $f->addressee_id : $f->requester_id);

        $presence = UserPresence::whereIn('user_id', $friendIds)->get()->keyBy('user_id');

        $result = $friendIds->map(function ($id) use ($presence) {
            $p = $presence->get($id);
            return [
                'user_id' => $id,
                'status' => $p->status ?? 'offline',
                'current_lobby_id' => $p->current_lobby_id ?? null,
                'last_seen_at' => optional($p?->last_seen_at)->toIso8601String(),
            ];
        })->values();

        return response()->json(['presence' => $result]);
    }
}
