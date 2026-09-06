<?php

namespace App\Http\Controllers;

use App\Events\GameInviteReceived;
use App\Models\Friendship;
use App\Models\GameInvite;
use App\Support\Broadcasting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameInviteController extends Controller
{
    // POST /invites/send
    public function send(Request $r)
    {
        $me = $r->user();

        $v = $r->validate([
            'receiver_id' => 'required|integer|exists:users,id',
            'game_id' => 'required|integer|exists:games,id',
            'lobby_id' => 'nullable|integer|exists:lobbies,id',
        ]);

        if ((int) $v['receiver_id'] === (int) $me->id) {
            return response()->json(['message' => 'You cannot invite yourself'], 422);
        }

        $isFriend = Friendship::where('status', 'accepted')
            ->where(fn ($q) => $q->where('requester_id', $me->id)->where('addressee_id', $v['receiver_id']))
            ->orWhere(fn ($q) => $q->where('requester_id', $v['receiver_id'])->where('addressee_id', $me->id))
            ->exists();

        if (! $isFriend) {
            return response()->json(['message' => 'You can only invite an accepted friend'], 422);
        }

        $invite = GameInvite::create([
            'sender_id' => $me->id,
            'receiver_id' => $v['receiver_id'],
            'game_id' => $v['game_id'],
            'lobby_id' => $v['lobby_id'] ?? null,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $invite->load(['game:id,title,kind', 'lobby:id,code']);

        Broadcasting::fire(new GameInviteReceived($invite->receiver_id, [
            'invite' => [
                'id' => $invite->id,
                'sender_id' => $invite->sender_id,
                'sender_name' => $me->name,
                'game_id' => $invite->game_id,
                'game_name' => $invite->game->title ?? null,
                'lobby_id' => $invite->lobby_id,
                'lobby_code' => $invite->lobby->code ?? null,
                'status' => $invite->status,
                'expires_at' => $invite->expires_at->toIso8601String(),
            ],
        ]));

        return response()->json(['invite' => $invite], 201);
    }

    // POST /invites/accept/{invite}
    public function accept(Request $r, string $invite)
    {
        $me = $r->user();

        return DB::transaction(function () use ($me, $invite) {
            $i = GameInvite::lockForUpdate()->findOrFail($invite);

            if ((int) $i->receiver_id !== (int) $me->id) {
                return response()->json(['message' => 'Only the recipient can accept this invite'], 403);
            }
            if ($i->status !== 'pending') {
                return response()->json(['message' => 'This invite has already been resolved'], 422);
            }
            if ($i->expires_at && $i->expires_at->isPast()) {
                $i->update(['status' => 'expired']);
                return response()->json(['message' => 'This invite has expired'], 422);
            }

            $i->update(['status' => 'accepted']);

            return response()->json(['invite' => $i, 'lobby_id' => $i->lobby_id]);
        });
    }

    // POST /invites/decline/{invite}
    public function decline(Request $r, string $invite)
    {
        $me = $r->user();

        return DB::transaction(function () use ($me, $invite) {
            $i = GameInvite::lockForUpdate()->findOrFail($invite);

            if ((int) $i->receiver_id !== (int) $me->id) {
                return response()->json(['message' => 'Only the recipient can decline this invite'], 403);
            }
            if ($i->status !== 'pending') {
                return response()->json(['message' => 'This invite has already been resolved'], 422);
            }

            $i->update(['status' => 'declined']);

            return response()->json(['ok' => true]);
        });
    }

    // GET /invites/pending
    public function pending(Request $r)
    {
        $me = $r->user();

        $invites = GameInvite::with(['sender:id,name,avatar_url', 'game:id,title,kind', 'lobby:id,code'])
            ->where('receiver_id', $me->id)
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('created_at')
            ->get();

        return response()->json(['invites' => $invites]);
    }
}
