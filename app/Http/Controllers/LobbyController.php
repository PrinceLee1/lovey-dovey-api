<?php

namespace App\Http\Controllers;

use App\Events\LobbyReactionSent;
use App\Notifications\PublicLobbyCreated;
use Illuminate\Http\Request;
use App\Models\Lobby;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
class LobbyController extends Controller
{
        public function indexPublic(Request $r) {
        return Lobby::query()
            ->where('privacy', 'Public')
            ->where('status', 'open')
            // "Private couple mode" hides a user's own lobbies from this
            // public discovery listing, even when marked Public — joinable
            // only via a direct invite link at that point.
            ->whereHas('host', fn ($q) => $q->where('private_profile', false))
            ->withCount('members')
            ->orderByRaw('COALESCE(start_at, "9999-12-31") asc')
            ->latest('start_at')               // secondary sort
            ->limit(30)
            ->get();
    }

    public function my(Request $r) {
        $user = $r->user();
        return $user->lobbies()               // <-- now exists
            ->withCount('members')
            ->latest('start_at')
            ->get();
    }

    public function store(Request $r) {
        $data = $r->validate([
            'name'         => ['required','string','max:80'],
            'max_players'  => ['required','integer','min:2','max:16'],
            'entry_coins'  => ['required','integer','min:0','max:100000'],
            'privacy'      => ['required', Rule::in(['Public','Private'])],
            'start_at'     => ['nullable','date'], // ISO string; treat as UTC client-side
            'game_kind'    => ['nullable','string','max:40'],
            'rules'        => ['nullable','array'],
        ]);

        $lobby = Lobby::create(array_merge($data, [
            'host_id' => $r->user()->id,
            'status'  => 'open',
        ]));

        // host auto-joins
        $lobby->members()->attach($r->user()->id, ['role'=>'host']);

        if ($lobby->privacy === 'Public') {
            $lobby->load('host');
            // A mail-transport hiccup here must never fail lobby creation.
            try {
                User::where('id', '!=', $r->user()->id)
                    ->where('status', 'active')
                    ->where('email_reminders', true)
                    ->chunkById(100, function ($users) use ($lobby) {
                        Notification::send($users, new PublicLobbyCreated($lobby));
                    });
            } catch (\Throwable $e) {
                Log::warning('Public lobby notification failed to send: '.$e->getMessage());
            }
        }

        return response()->json([
            'lobby' => $lobby->fresh(),
            'invite_url' => url("/lobby/{$lobby->code}"),
            'code' => $lobby->code,
        ], 201);
    }

    public function showByCode(Request $r, string $code) {
        $lobby = Lobby::where('code',$code)->firstOrFail();
        $members = $lobby->members()->select('users.id','users.name')->get();
        return response()->json(compact('lobby','members'));
    }

    public function join(Request $r, string $code) {
        $lobby = Lobby::where('code',$code)->firstOrFail();

        // An existing member (including the host) re-entering must never be
        // blocked by lobby status/capacity — those only gate new joiners.
        // Re-attaching would also silently overwrite the host's pivot role
        // with 'player', so skip it entirely once already a member.
        $alreadyMember = $lobby->members()->where('users.id', $r->user()->id)->exists();

        if ($alreadyMember) {
            return response()->json(['ok'=>true]);
        }

        if ($lobby->status !== 'open') {
            return response()->json(['message'=>'Lobby is not open'], 422);
        }

        $count = $lobby->members()->count();
        if ($count >= $lobby->max_players) {
            return response()->json(['message'=>'Lobby is full'], 422);
        }

        $lobby->members()->attach($r->user()->id, ['role'=>'player']);
        return response()->json(['ok'=>true]);
    }

    public function leave(Request $r, string $code) {
        $lobby = Lobby::where('code',$code)->firstOrFail();
        $lobby->members()->detach($r->user()->id);
        return response()->json(['ok'=>true]);
    }

    public function close(Request $r, string $code) {
        $lobby = Lobby::where('code',$code)->firstOrFail();
        abort_unless($lobby->host_id === $r->user()->id, 403);
        $lobby->update(['status'=>'ended']);
        return response()->json(['ok'=>true]);
    }
    public function destroy(Request $r, string $id) {
        $lobby = Lobby::where('id',$id)->firstOrFail();
        abort_unless($lobby->host_id === $r->user()->id, 403);
        $lobby->delete();
        return response()->json(['ok'=>true]);
    }
    public function members(Request $r, string $code) {
        $lobby = Lobby::where('code',$code)->firstOrFail();
        $members = $lobby->members()->select('users.id','users.name','users.avatar_url')->get();
        return response()->json(['members'=>$members]);
    }
    public function sendReaction(Request $request, string $code)
    {
    $request->validate(['emoji' => 'required|string|max:10']);

    $lobby = Lobby::where('code', $code)->firstOrFail();

    // Broadcast to everyone EXCEPT the sender
    // (sender already shows it locally via optimistic update)
    broadcast(new LobbyReactionSent(
        $request->emoji,
        $request->user()->name,
        $code
    ))->toOthers();

    return response()->json(['ok' => true]);
    }
}
