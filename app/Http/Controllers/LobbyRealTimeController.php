<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\LobbyGameEnded;
use App\Events\LobbyGameStarted;
use App\Events\LobbyGameUpdate;
use App\Events\LobbyMessageCreated;
use App\Models\Lobby;
use App\Models\LobbyGameSession;
use App\Models\LobbyMessage;
use Illuminate\Support\Facades\Gate;
class LobbyRealTimeController extends Controller
{
     // Chat
  public function messages(Request $r, string $code) {
    $lobby = Lobby::where('code',$code)->firstOrFail();
    $msgs = LobbyMessage::with('user:id,name,avatar_url')
      ->where('lobby_id',$lobby->id)->latest()->limit(50)->get()->reverse()->values();
    return response()->json($msgs);
  }

  public function postMessage(Request $r, string $code)
  {
      $data = $r->validate([
          'body' => 'required|string|max:2000',
      ]);

      $lobby = Lobby::where('code', $code)->firstOrFail();

      // (optional) ensure user belongs to lobby before posting
      // abort_unless($lobby->members()->whereKey($r->user()->id)->exists() || $lobby->host_id === $r->user()->id, 403);

      $msg = LobbyMessage::create([
          'lobby_id' => $lobby->id,
          'user_id'  => $r->user()->id,
          'body'     => $data['body'],
      ])->load('user:id,name');

      // Broadcast to others ONLY (the sender won’t receive this event)
      broadcast(new LobbyMessageCreated($msg, $code))->toOthers();

      // Return the real message so the sender can swap their optimistic one
      return response()->json([
          'message' => [
              'id'         => $msg->id,
              'body'       => $msg->body,
              'created_at' => $msg->created_at->toISOString(),
              'user'       => ['id' => $msg->user_id, 'name' => $msg->user->name],
          ],
      ]);
  }

  // Start/End game
  public function startGame(Request $r, string $code) {
    $data = $r->validate([
      'kind'     => 'required|in:trivia,charades_ai,hot_seat,would_you_rather,spice_dice',
      'settings' => 'nullable|array'
    ]);
    $lobby = Lobby::where('code',$code)->firstOrFail();
    abort_unless($lobby->host_id === $r->user()->id, 403);

    $session = LobbyGameSession::create([
      'lobby_id'=>$lobby->id,
      'started_by'=>$r->user()->id,
      'kind'=>$data['kind'],
      'settings'=>$data['settings'] ?? [],
      'status'=>'active',
    ]);

    broadcast(new LobbyGameStarted($session, $code))->toOthers();

    return response()->json(['session' => $session->fresh()]);
  }

  // Host (or server) can push state deltas to everyone in /game.{id}
  public function pushUpdate(Request $r, string $code, int $sessionId) {
    $payload = $r->validate(['type'=>'required|string','data'=>'nullable|array']);
    // (optional) ensure user belongs to lobby
    broadcast(new LobbyGameUpdate($sessionId, $payload))->toOthers();
    return response()->json(['ok'=>true]);
  }

  public function endGame(Request $r, string $code, int $sessionId) {
    $data = $r->validate(['result'=>'required|array']);
    $lobby = Lobby::where('code',$code)->firstOrFail();
    //only host (or server) can end game to prevent cheating, return 'You are not the host' instead of 'Session not found' to avoid confusion for cheaters
    abort_unless($lobby->host_id == $r->user()->id, 403);

    $session = LobbyGameSession::where('id',$sessionId)->where('lobby_id',$lobby->id)->firstOrFail();
    $session->update(['status'=>'ended','result'=>$data['result'],'ended_at'=>now()]);

    broadcast(new LobbyGameEnded($session, $code))->toOthers();

    return response()->json(['ok'=>true]);
  }

  public function sessions(Request $r, string $code) {
    $lobby = Lobby::where('code',$code)->firstOrFail();
    $rows = LobbyGameSession::where('lobby_id',$lobby->id)->latest()->limit(20)->get();
    return response()->json($rows);
  }
  public function gameAction(Request $request, string $code, int $sessionId)
  {
      $request->validate([
          'type' => 'required|string|in:state,vote,buzz,answer,tick',
          'data' => 'nullable|array',
      ]);
  
      // Verify lobby exists and user is a member
      $lobby = Lobby::where('code', $code)->firstOrFail();
  
      // Optional: verify session belongs to this lobby
      // $session = LobbyGameSession::where('id', $sessionId)
      //     ->where('lobby_id', $lobby->id)
      //     ->firstOrFail();
  
      $payload = [
          'type' => $request->type,
          'data' => $request->data ?? [],
          'by'   => $request->user()->name,
      ];
  
      // Broadcast to ALL players in the lobby game channel
      // Frontend: echo.channel(`lobby-game.${sessionId}`).listen(".LobbyGameUpdate", ...)
      broadcast(new LobbyGameUpdate($sessionId, $payload));
  
      return response()->json(['ok' => true]);
  }
}
