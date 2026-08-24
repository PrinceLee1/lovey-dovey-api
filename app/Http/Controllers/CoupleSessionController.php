<?php

namespace App\Http\Controllers;

use App\Events\CoupleSessionCreated;
use App\Events\CoupleSessionInvited;
use App\Events\CoupleSessionUpdated;
use App\Models\GameSession;
use App\Models\Partner;
use App\Models\User;
use App\Notifications\CoupleGameInvite;
use App\Support\Broadcasting;
use App\Support\TruthDarePrompts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CoupleSessionController extends Controller
{
    /**
     * Invite the caller's active partner to a new couple game. Creates the
     * session in 'waiting' status — it only becomes playable once the
     * partner calls accept().
     */
    public function invite(Request $r)
    {
        $r->validate(['kind' => 'required|string|max:40']);

        $me = $r->user();

        $pair = Partner::where('status', 'active')
            ->where(fn ($q) => $q->where('user_a_id', $me->id)->orWhere('user_b_id', $me->id))
            ->firstOrFail();

        $partnerId = $pair->user_a_id === $me->id ? $pair->user_b_id : $pair->user_a_id;

        $session = GameSession::create([
            'code' => Str::upper(Str::random(6)),
            'kind' => $r->kind,
            'created_by' => $me->id,
            'partner_user_id' => $partnerId,
            'turn_user_id' => $me->id, // creator starts once accepted
            'state' => ['phase' => 'picking', 'skips' => 0, 'done' => 0, 'xp' => 0, 'usedPrompts' => []],
            'status' => 'waiting',
        ]);

        Broadcasting::fire(new CoupleSessionInvited(
            receiverUserId: $partnerId,
            code: $session->code,
            kind: $session->kind,
            inviterName: $me->name,
            pairId: $pair->id,
        ));

        // A mail-transport hiccup here must never fail the invite itself —
        // the real-time broadcast above already reached the partner if online.
        if ($partner = User::find($partnerId)) {
            try {
                $partner->notify(new CoupleGameInvite($session, $me));
            } catch (\Throwable $e) {
                Log::warning('Couple game invite email failed to send: '.$e->getMessage());
            }
        }

        return response()->json($this->present($session), 201);
    }

    /**
     * The invited partner accepts, starting the session for both players.
     * Idempotent: re-accepting an already-active session just returns it.
     */
    public function accept(Request $r, string $code)
    {
        $me = $r->user();
        $s = GameSession::where('code', $code)->lockForUpdate()->firstOrFail();

        abort_unless(in_array($me->id, [$s->created_by, $s->partner_user_id]), 403);

        if ($s->status === 'active') {
            return response()->json($this->present($s));
        }

        if ($s->status !== 'waiting') {
            return response()->json(['message' => 'This invite is no longer available.'], 422);
        }

        $s->status = 'active';
        $s->started_at = now();
        $s->save();

        Broadcasting::fire(new CoupleSessionCreated($s), toOthers: true);

        return response()->json($this->present($s));
    }

    public function show(Request $r, string $code)
    {
        $s = GameSession::where('code', $code)->firstOrFail();

        abort_unless(in_array($r->user()->id, [$s->created_by, $s->partner_user_id]), 403);

        return response()->json($this->present($s));
    }

    public function action(Request $r, string $code)
    {
        $v = $r->validate([
            'type' => 'required|string',
            'payload' => 'nullable|array',
        ]);

        $me = $r->user();
        $s = GameSession::where('code', $code)->lockForUpdate()->firstOrFail();

        abort_unless(in_array($me->id, [$s->created_by, $s->partner_user_id]), 403);

        // Either partner can end the session, any time, regardless of turn.
        if ($v['type'] === 'finish') {
            $s->status = 'ended';
            $s->finished_at = now();
            $s->save();

            Broadcasting::fire(new CoupleSessionUpdated($s), toOthers: true);

            return response()->json($this->present($s));
        }

        if ($s->status !== 'active') {
            abort(422, 'This session is not active.');
        }

        if ($s->turn_user_id !== $me->id) {
            abort(422, 'Not your turn.');
        }

        $state = $s->state ?? [];
        $phase = $state['phase'] ?? 'picking';

        switch ($v['type']) {
            case 'spin':
                if ($phase !== 'picking') {
                    abort(422, 'A prompt is already in progress.');
                }
                $type = random_int(0, 1) ? 'dare' : 'truth';
                $used = $state['usedPrompts'] ?? [];
                $prompt = TruthDarePrompts::pick($type, $s->kind, $used);
                $state['phase'] = 'prompt';
                $state['currentType'] = $type;
                $state['currentPrompt'] = $prompt;
                $used[] = $prompt;
                $state['usedPrompts'] = array_slice($used, -10);
                break;

            case 'done':
                if ($phase !== 'prompt') {
                    abort(422, 'No active prompt to complete.');
                }
                $state['done'] = ($state['done'] ?? 0) + 1;
                $state['xp'] = ($state['xp'] ?? 0) + 10;
                $state['phase'] = 'picking';
                $state['currentType'] = null;
                $state['currentPrompt'] = null;
                $s->round = $s->round + 1;
                $s->turn_user_id = $me->id === $s->created_by ? $s->partner_user_id : $s->created_by;
                break;

            case 'skip':
                if ($phase !== 'prompt') {
                    abort(422, 'No active prompt to skip.');
                }
                $state['skips'] = ($state['skips'] ?? 0) + 1;
                $state['phase'] = 'picking';
                $state['currentType'] = null;
                $state['currentPrompt'] = null;
                $s->round = $s->round + 1;
                $s->turn_user_id = $me->id === $s->created_by ? $s->partner_user_id : $s->created_by;
                break;

            default:
                abort(422, 'Unknown action type.');
        }

        $s->state = $state;
        $s->save();

        Broadcasting::fire(new CoupleSessionUpdated($s), toOthers: true);

        return response()->json($this->present($s));
    }

    private function present(GameSession $s): array
    {
        return [
            'code' => $s->code,
            'kind' => $s->kind,
            'round' => $s->round,
            'turnUserId' => $s->turn_user_id,
            'status' => $s->status,
            'state' => $s->state,
            'createdBy' => $s->created_by,
            'partnerUserId' => $s->partner_user_id,
        ];
    }
}
