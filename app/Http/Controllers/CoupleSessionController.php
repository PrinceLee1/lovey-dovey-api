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
    private const PROMPT_KINDS = ['truth_dare', 'truth_dare_erotic', 'spice_dice'];
    private const EMOJI_POOL = ['🍕','🎬','🎧','🌮','☕️','🎮','📚','🌈','🧋','🍣','🐶','✈️','🍫','🎵','🏖️','🌙'];
    private const MATCH_PAIRS = 6;
    private const CHAT_MINUTES = 5;

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
            'state' => $this->initialState($r->kind),
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

        if ($s->kind === 'emoji_chat') {
            $state = $s->state ?? [];
            $state['endsAt'] = now()->addMinutes(self::CHAT_MINUTES)->toISOString();
            $s->state = $state;
        }

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

        // Emoji Chat has no turns — either partner can send at any time.
        if ($s->kind === 'emoji_chat') {
            $this->applyChatAction($s, $me, $v['type'], $v['payload'] ?? []);
        } else {
            if ($s->turn_user_id !== $me->id) {
                abort(422, 'Not your turn.');
            }

            if (in_array($s->kind, self::PROMPT_KINDS)) {
                $this->applyPromptAction($s, $me, $v['type']);
            } elseif ($s->kind === 'memory_match') {
                $this->applyMatchAction($s, $me, $v['type'], $v['payload'] ?? []);
            } else {
                abort(422, 'Unsupported game kind.');
            }
        }

        $s->save();

        Broadcasting::fire(new CoupleSessionUpdated($s), toOthers: true);

        return response()->json($this->present($s));
    }

    // ── Truth or Dare / Truth or Dare Plus / Spice Dice ───────────────────────
    // Spice Dice reuses the exact same spin → prompt → done/skip shape, just
    // always rolling a dare (no truth option) from the spicier prompt pool.
    private function applyPromptAction(GameSession $s, User $me, string $type): void
    {
        $state = $s->state ?? [];
        $phase = $state['phase'] ?? 'picking';

        switch ($type) {
            case 'spin':
                if ($phase !== 'picking') {
                    abort(422, 'A prompt is already in progress.');
                }
                $promptType = $s->kind === 'spice_dice' ? 'dare' : (random_int(0, 1) ? 'dare' : 'truth');
                $used = $state['usedPrompts'] ?? [];
                $prompt = TruthDarePrompts::pick($promptType, $s->kind, $used);
                $state['phase'] = 'prompt';
                $state['currentType'] = $promptType;
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
                $s->turn_user_id = $this->otherPlayer($s, $me);
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
                $s->turn_user_id = $this->otherPlayer($s, $me);
                break;

            default:
                abort(422, 'Unknown action type.');
        }

        $s->state = $state;
    }

    // ── Emoji Chat ──────────────────────────────────────────────────────────
    // No turns: both partners can send at any time. Server enforces
    // emoji-only content and caps the thread so state doesn't grow unbounded.
    private function applyChatAction(GameSession $s, User $me, string $type, array $payload): void
    {
        if ($type !== 'message') {
            abort(422, 'Unknown action type.');
        }

        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '' || preg_match('/[a-zA-Z0-9]/u', $text) || ! preg_match('/\p{Extended_Pictographic}/u', $text)) {
            abort(422, 'Emojis only.');
        }

        $state = $s->state ?? [];
        $messages = $state['messages'] ?? [];
        $messages[] = ['from' => $me->id, 'text' => $text, 'at' => now()->toISOString()];
        $state['messages'] = array_slice($messages, -100);

        $s->state = $state;
        $s->round = $s->round + 1;
    }

    // ── Memory Match ────────────────────────────────────────────────────────
    // Server holds the deck; a match keeps the same player's turn (bonus
    // continue), a mismatch passes it — mirroring the local pass-and-play
    // version's rule. justRevealed lets the client briefly show a mismatch
    // before it's cleared on the very next state read.
    private function applyMatchAction(GameSession $s, User $me, string $type, array $payload): void
    {
        if ($type !== 'flip') {
            abort(422, 'Unknown action type.');
        }

        $state = $s->state ?? [];
        $deck = $state['deck'] ?? [];
        $flipped = $state['flipped'] ?? [];
        $index = (int) ($payload['index'] ?? -1);

        if (! isset($deck[$index]) || $deck[$index]['matched'] || in_array($index, $flipped)) {
            abort(422, 'Invalid card.');
        }

        $flipped[] = $index;
        $state['justRevealed'] = null;

        if (count($flipped) < 2) {
            $state['flipped'] = $flipped;
            $s->state = $state;

            return;
        }

        [$a, $b] = $flipped;
        $isMatch = $deck[$a]['value'] === $deck[$b]['value'];

        if ($isMatch) {
            $deck[$a]['matched'] = true;
            $deck[$b]['matched'] = true;
            $state['matches'] = ($state['matches'] ?? 0) + 1;
            // Same player continues on a match.
        } else {
            $s->turn_user_id = $this->otherPlayer($s, $me);
        }

        $state['deck'] = $deck;
        $state['flipped'] = [];
        $state['justRevealed'] = ['indexes' => [$a, $b], 'matched' => $isMatch];
        $state['moves'] = ($state['moves'] ?? 0) + 1;

        if ($state['matches'] >= self::MATCH_PAIRS) {
            $state['xp'] = self::MATCH_PAIRS * 15;
        }

        $s->state = $state;
        $s->round = $s->round + 1;
    }

    private function otherPlayer(GameSession $s, User $me): int
    {
        return $me->id === $s->created_by ? $s->partner_user_id : $s->created_by;
    }

    private function initialState(string $kind): array
    {
        if (in_array($kind, self::PROMPT_KINDS)) {
            return ['phase' => 'picking', 'skips' => 0, 'done' => 0, 'xp' => 0, 'usedPrompts' => []];
        }

        if ($kind === 'emoji_chat') {
            return ['messages' => [], 'endsAt' => null];
        }

        if ($kind === 'memory_match') {
            return [
                'deck' => $this->buildMatchDeck(),
                'flipped' => [],
                'matches' => 0,
                'moves' => 0,
                'xp' => 0,
                'justRevealed' => null,
            ];
        }

        return [];
    }

    private function buildMatchDeck(): array
    {
        $values = collect(self::EMOJI_POOL)->shuffle()->take(self::MATCH_PAIRS)->all();
        $cards = collect($values)->flatMap(fn ($v) => [$v, $v])->shuffle()->values();

        return $cards->map(fn ($value, $i) => ['id' => $i, 'value' => $value, 'matched' => false])->all();
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
