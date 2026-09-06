<?php

namespace App\Http\Controllers;

use App\Actions\LogFriendActivity;
use App\Events\FriendRequestAccepted;
use App\Events\FriendRequestReceived;
use App\Models\Friendship;
use App\Models\User;
use App\Support\Broadcasting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FriendshipController extends Controller
{
    // POST /friends/request/{user}
    public function sendRequest(Request $r, string $user)
    {
        $me = $r->user();
        $target = User::findOrFail($user);

        if ((int) $target->id === (int) $me->id) {
            return response()->json(['message' => 'You cannot send a friend request to yourself'], 422);
        }

        return DB::transaction(function () use ($me, $target) {
            $existing = Friendship::where(fn ($q) => $q->where('requester_id', $me->id)->where('addressee_id', $target->id))
                ->orWhere(fn ($q) => $q->where('requester_id', $target->id)->where('addressee_id', $me->id))
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return response()->json(['message' => 'A friend request already exists between you and this user'], 422);
            }

            $friendship = Friendship::create([
                'requester_id' => $me->id,
                'addressee_id' => $target->id,
                'status' => 'pending',
            ]);

            Broadcasting::fire(new FriendRequestReceived($target->id, [
                'request' => [
                    'id' => $friendship->id,
                    'created_at' => $friendship->created_at,
                    'requester' => ['id' => $me->id, 'name' => $me->name, 'avatar_url' => $me->avatar_url],
                ],
            ]));

            return response()->json(['friendship' => $friendship], 201);
        });
    }

    // POST /friends/accept/{friendship}
    public function accept(Request $r, string $friendship)
    {
        $me = $r->user();

        return DB::transaction(function () use ($me, $friendship) {
            $f = Friendship::with('requester:id,name')->lockForUpdate()->findOrFail($friendship);

            if ((int) $f->addressee_id !== (int) $me->id) {
                return response()->json(['message' => 'Only the recipient can accept this request'], 403);
            }
            if ($f->status !== 'pending') {
                return response()->json(['message' => 'This request has already been resolved'], 422);
            }

            $f->update(['status' => 'accepted']);

            Broadcasting::fire(new FriendRequestAccepted($f->requester_id, [
                'friend' => ['id' => $me->id, 'name' => $me->name, 'avatar_url' => $me->avatar_url],
            ]));

            LogFriendActivity::log($me->id, 'friend_added', ['friend_id' => $f->requester_id, 'friend_name' => $f->requester->name]);
            LogFriendActivity::log($f->requester_id, 'friend_added', ['friend_id' => $me->id, 'friend_name' => $me->name]);

            return response()->json(['friendship' => $f]);
        });
    }

    // POST /friends/reject/{friendship}
    public function reject(Request $r, string $friendship)
    {
        $me = $r->user();

        return DB::transaction(function () use ($me, $friendship) {
            $f = Friendship::lockForUpdate()->findOrFail($friendship);

            if ((int) $f->addressee_id !== (int) $me->id) {
                return response()->json(['message' => 'Only the recipient can reject this request'], 403);
            }
            if ($f->status !== 'pending') {
                return response()->json(['message' => 'This request has already been resolved'], 422);
            }

            $f->delete();

            return response()->json(['ok' => true]);
        });
    }

    // POST /friends/block/{user}
    public function block(Request $r, string $user)
    {
        $me = $r->user();
        $target = User::findOrFail($user);

        if ((int) $target->id === (int) $me->id) {
            return response()->json(['message' => 'You cannot block yourself'], 422);
        }

        return DB::transaction(function () use ($me, $target) {
            $f = Friendship::where(fn ($q) => $q->where('requester_id', $me->id)->where('addressee_id', $target->id))
                ->orWhere(fn ($q) => $q->where('requester_id', $target->id)->where('addressee_id', $me->id))
                ->lockForUpdate()
                ->first();

            if ($f) {
                $f->update(['status' => 'blocked']);
            } else {
                $f = Friendship::create([
                    'requester_id' => $me->id,
                    'addressee_id' => $target->id,
                    'status' => 'blocked',
                ]);
            }

            return response()->json(['friendship' => $f]);
        });
    }

    // DELETE /friends/{friendship}
    public function destroy(Request $r, string $friendship)
    {
        $me = $r->user();
        $f = Friendship::findOrFail($friendship);

        if ((int) $f->requester_id !== (int) $me->id && (int) $f->addressee_id !== (int) $me->id) {
            return response()->json(['message' => 'You are not part of this friendship'], 403);
        }

        $f->delete();

        return response()->json(['ok' => true]);
    }

    // GET /friends
    public function index(Request $r)
    {
        $me = $r->user();

        $friendships = Friendship::forUser($me->id)->where('status', 'accepted')->get();
        $otherIds = $friendships->map(fn ($f) => (int) $f->requester_id === (int) $me->id ? $f->addressee_id : $f->requester_id);

        $friends = User::select('users.id', 'users.name', 'users.avatar_url')
            ->selectRaw('user_presence.status as presence_status, user_presence.current_lobby_id, user_presence.last_seen_at')
            ->leftJoin('user_presence', 'user_presence.user_id', '=', 'users.id')
            ->whereIn('users.id', $otherIds)
            ->get();

        return response()->json(['friends' => $friends]);
    }

    // GET /friends/requests
    public function requests(Request $r)
    {
        $me = $r->user();

        $requests = Friendship::with(['requester:id,name,avatar_url'])
            ->where('addressee_id', $me->id)
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json(['requests' => $requests]);
    }
}
