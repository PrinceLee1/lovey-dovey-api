<?php

namespace App\Http\Controllers;

use App\Events\CoupleSessionInvited;
use Illuminate\Http\Request;
use App\Models\GameSession;
use App\Models\Partner;
use App\Events\CoupleSessionCreated;
use App\Events\CoupleSessionUpdated;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
class CoupleSessionController extends Controller
{
    public function create(Request $r)
    {
        $r->validate(['kind'=>'required|string|max:40']);

        $me = $r->user();

        $pair = Partner::where('status','active')
          ->where(fn($q)=>$q->where('user_a_id',$me->id)->orWhere('user_b_id',$me->id))
          ->firstOrFail();

        $partnerId = $pair->user_a_id === $me->id ? $pair->user_b_id : $pair->user_a_id;

        $session = DB::transaction(function() use ($r, $me, $partnerId) {
            $code = Str::upper(Str::random(6));
            // initial state for truth/dare
            $state = [
              'skips'=>0, 'done'=>0,
              'stack'=>[],   // server can fill with AI/generated prompts
            ];
            return GameSession::create([
              'code' => $code,
              'kind' => $r->kind,
              'created_by' => $me->id,
              'partner_user_id' => $partnerId,
              'turn_user_id' => $me->id,       // creator starts
              'state' => $state,
              'status'=> 'active',
              'started_at'=> now(),
            ]);
        });

        broadcast(new CoupleSessionCreated($session))->toOthers();

        return response()->json($session, 201);
    }

    public function show(Request $r, string $code)
    {
        $s = GameSession::where('code',$code)->firstOrFail();
        $r->user()->can('view', $s); // optional policy
        return $s;
    }

    public function action(Request $r, string $code)
    {
        $v = $r->validate([
          'type'=>'required|string',           // 'pick', 'skip', 'finish'
          'payload'=>'nullable|array'
        ]);

        $me = $r->user();
        $s = GameSession::where('code',$code)->lockForUpdate()->firstOrFail();

        // authorize: must be one of the pair
        if(!in_array($me->id, [$s->created_by, $s->partner_user_id])) {
          abort(403);
        }

        // only current turn can act (except finish/admin actions)
        if ($v['type'] !== 'finish' && $s->turn_user_id !== $me->id) {
          abort(422, 'Not your turn.');
        }

        $state = $s->state ?? [];

        switch ($v['type']) {
          case 'pick': // truth or dare chosen
            // mutate state (e.g., pop a prompt from stack, increment done)
            $state['done'] = ($state['done'] ?? 0) + 1;
            $s->round = $s->round + 1;
            $s->turn_user_id = ($me->id === $s->created_by) ? $s->partner_user_id : $s->created_by;
            break;

          case 'skip':
            $state['skips'] = ($state['skips'] ?? 0) + 1;
            $s->turn_user_id = ($me->id === $s->created_by) ? $s->partner_user_id : $s->created_by;
            break;

          case 'finish':
            $s->status = 'ended';
            $s->finished_at = now();
            break;
        }

        $s->state = $state;
        $s->save();

        broadcast(new CoupleSessionUpdated($s))->toOthers();

        return $s;
    }
  public function start(Request $r)
  {
    $user = $r->user();
    $kind = $r->validate(['kind'=>'required|string'])['kind'];

    // find active partner
    $pair = Partner::where('status','active')
        ->where(fn($q)=>$q->where('user_a_id',$user->id)->orWhere('user_b_id',$user->id))
        ->firstOrFail();

    $partnerId = $pair->user_a_id === $user->id ? $pair->user_b_id : $pair->user_a_id;

    // create a code + persist a row (optional)
    $code = Str::upper(Str::random(6));
    // ...save to sessions table if you have one

    // broadcast invite to partner
    broadcast(new CoupleSessionInvited(
        receiverUserId: $partnerId,
        code: $code,
        kind: $kind,
        inviterName: $user->name,
        pairId: $pair->id
    ));

    return response()->json(['code'=>$code], 201);
  }
}
