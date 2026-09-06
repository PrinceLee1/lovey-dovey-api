<?php

namespace App\Http\Controllers;

use App\Events\UserPresenceUpdated;
use App\Models\Friendship;
use App\Models\User;
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

        $allFriendships = Friendship::forUser($me->id)->get();
        $accepted = $allFriendships->where('status', 'accepted');
        $friendIds = $accepted->map(fn ($f) => (int) $f->requester_id === (int) $me->id ? $f->addressee_id : $f->requester_id);

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

        // Anyone already connected to the caller in some way (accepted,
        // pending in either direction, or blocked) shouldn't show up as a
        // discoverable "online other" — they belong in the Friends or
        // Requests tab instead, not Find Players.
        // toBase() is required here: $allFriendships is an Eloquent Collection,
        // and map() preserves that class even though the mapped values are
        // plain ints, not models. Eloquent Collection's unique() (with no
        // key argument) calls getDictionary(), which assumes every item is a
        // Model and calls ->getKey() on it — crashing with "Call to a member
        // function getKey() on int". toBase() converts to a plain Support
        // Collection first, where unique() just does a normal scalar compare.
        $excludedIds = $allFriendships
            ->map(fn ($f) => (int) $f->requester_id === (int) $me->id ? $f->addressee_id : $f->requester_id)
            ->toBase()
            ->push($me->id)
            ->unique()
            ->values();

        $onlineOthers = User::select('users.id', 'users.name', 'users.avatar_url')
            ->join('user_presence', 'user_presence.user_id', '=', 'users.id')
            ->where('user_presence.status', 'online')
            ->whereNotIn('users.id', $excludedIds)
            ->limit(50)
            ->get();

        return response()->json(['presence' => $result, 'online_others' => $onlineOthers]);
    }
}
