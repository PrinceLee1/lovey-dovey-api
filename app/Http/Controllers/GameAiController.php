<?php

namespace App\Http\Controllers;

use Cache;
use Http;
use Illuminate\Http\Request;

class GameAiController extends Controller
{
        /**
     * Generate Truth/Dare prompts using OpenAI Structured Outputs
     * and cache results in Redis.
     */
    public function truthDare(Request $request)
    {
        $data = $request->validate([
            'category'      => 'nullable|string',      // Romantic | Playful | Spicy | Challenge
            'tone'          => 'nullable|string',      // PG-13 by default
            'count_truths'  => 'nullable|integer|min:0|max:40',
            'count_dares'   => 'nullable|integer|min:4|max:40',
            'names'         => 'nullable|array',       // ["Alex","Maya"]
            'personalize'   => 'nullable|boolean',     // if false, cache is shared (ignores names)
        ]);

        $category     = $data['category']     ?? 'Romantic';
        $tone         = $data['tone']         ?? 'PG-13';
        $countTruths  = array_key_exists('count_truths', $data) ? (int)$data['count_truths'] : 12;
        $countDares   = $data['count_dares']  ?? 12;
        $names        = $data['names']        ?? null;
        $personalize  = $data['personalize']  ?? true;

        // ----- Cache key (toggle personalization to improve hit rate) -----
        $keyParts = [
            'td',
            'v1',                 // bump if you change prompts/instructions
            $category,
            $tone,
            "t{$countTruths}",
            "d{$countDares}",
        ];
        if ($personalize && $names) {
            $keyParts[] = 'n:' . substr(sha1(implode('|', $names)), 0, 10);
        }
        $cacheKey = implode(':', $keyParts);

        // 6 hours cache (tune for your needs)
        $ttl = now()->addHours(6);

        // Use cache if available
        $cached = Cache::get($cacheKey);
        if ($cached && is_array($cached) && isset($cached['truths'], $cached['dares'])) {
            return response()->json($cached);
        }

        // ----- Structured Outputs request -----
        // JSON Schema guaranteeing { truths: string[], dares: string[] }
        $jsonSchema = [
            'name'   => 'truth_dare_schema',
            'strict' => true, // require exact schema adherence
            'schema' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['truths', 'dares'],
                'properties'           => [
                    'truths' => [
                        'type'     => 'array',
                        'minItems' => $countTruths,
                        'maxItems' => $countTruths,
                        'items'    => [
                            'type'      => 'string',
                            // length is approximate; the instruction handles the “4–18 words” nuance
                            'minLength' => 4,
                            'maxLength' => 180,
                        ],
                    ],
                    'dares' => [
                        'type'     => 'array',
                        'minItems' => $countDares,
                        'maxItems' => $countDares,
                        'items'    => [
                            'type'      => 'string',
                            'minLength' => 4,
                            'maxLength' => 180,
                        ],
                    ],
                ],
            ],
        ];

        $system = "You generate short, engaging Truth-or-Dare prompts for adult couples.
Keep everything {$tone}; do not include explicit sexual content or illegal/unsafe acts.
Prompts must be concise, warm, playful, and immediately doable. Avoid personal data collection.";

        $who = $names ? implode(' & ', $names) : 'two partners';
        $user = trim("
Category: {$category}
Players: {$who}

Produce exactly:
- {$countTruths} unique TRUTHS (open-ended, inviting)
- {$countDares} unique DARES (small, safe actions)
Each item 4–18 words. No duplicates, no negativity, no medical/legal advice.
Return only JSON that satisfies the schema.
");

        // Chat Completions with Structured Outputs by setting response_format.json_schema + strict:true
        // Docs: Structured Outputs (OpenAI) show using response_format + json_schema with strict.  :contentReference[oaicite:0]{index=0}
        $payload = [
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'temperature' => 0.9,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'response_format' => [
                'type'        => 'json_schema',
                'json_schema' => $jsonSchema,
            ],
        ];

        $resp = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(40)
            ->post('https://api.openai.com/v1/chat/completions', $payload)
            ->throw()
            ->json();

        // With Structured Outputs on Chat Completions, the assistant's message content is JSON
        $content = $resp['choices'][0]['message']['content'] ?? '{}';
        $parsed  = json_decode($content, true) ?: [];

        // Minimal sanitize & uniq
        $clean = function ($arr) {
            return collect($arr ?? [])
                ->filter(fn ($s) => is_string($s) && mb_strlen(trim($s)) >= 4)
                ->map(fn ($s) => trim(preg_replace('/\s+/', ' ', $s)))
                ->unique()
                ->values()
                ->all();
        };

        $result = [
            'truths' => $clean($parsed['truths'] ?? []),
            'dares'  => $clean($parsed['dares']  ?? []),
        ];

        // backstop: if model returned fewer than requested, don’t cache a “bad” batch
        if (count($result['truths']) >= $countTruths && count($result['dares']) >= $countDares) {
            Cache::put($cacheKey, $result, $ttl);
        }

        return response()->json($result);
    }
}
