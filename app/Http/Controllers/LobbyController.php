<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Lobby;
use Illuminate\Validation\Rule;
class LobbyController extends Controller
{
        public function indexPublic(Request $r) {
        return Lobby::query()
            ->where('privacy', 'Public')
            ->where('status', 'open')
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

        if ($lobby->status !== 'open') {
            return response()->json(['message'=>'Lobby is not open'], 422);
        }

        $count = $lobby->members()->count();
        if ($count >= $lobby->max_players) {
            return response()->json(['message'=>'Lobby is full'], 422);
        }

        $lobby->members()->syncWithoutDetaching([$r->user()->id => ['role'=>'player']]);
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
}
