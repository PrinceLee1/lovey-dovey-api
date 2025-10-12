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
Keep everything {$tone};.
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
    public function trivia(Request $request)
    {
        $v = $request->validate([
            'category'   => 'nullable|string',                 // e.g. General, Pop Culture, Movies, Music
            'difficulty' => 'nullable|in:Easy,Medium,Hard',    // optional
            'count'      => 'nullable|integer|min:6|max:40',   // number of questions
            'names'      => 'nullable|array',                  // ["Alex","Maya"] (optional flavor)
            'personalize'=> 'nullable|boolean',
        ]);

        $category   = $v['category']   ?? 'General';
        $difficulty = $v['difficulty'] ?? 'Medium';
        $count      = $v['count']      ?? 12;
        $names      = $v['names']      ?? null;
        $personal   = $v['personalize'] ?? false; // default false to increase cache hits

        // Cache key (exclude names by default)
        $key = implode(':', [
            'trivia','v1',
            strtolower($category),
            strtolower($difficulty),
            "n{$count}"
        ]);
        if ($personal && $names) {
            $key .= ':p:' . substr(sha1(implode('|', $names)), 0, 10);
        }

        if ($cached = Cache::get($key)) {
            return response()->json($cached);
        }

        $schema = [
            'name'   => 'trivia_questions',
            'strict' => true,
            'schema' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['questions'],
                'properties'           => [
                    'questions' => [
                        'type'     => 'array',
                        'minItems' => $count,
                        'maxItems' => $count,
                        'items'    => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['question','options','correctIndex','category','difficulty'],
                            'properties'           => [
                                'question' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 240],
                                'options'  => [
                                    'type'     => 'array',
                                    'minItems' => 4,
                                    'maxItems' => 4,
                                    'items'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                                ],
                                'correctIndex' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3],
                                'category'     => ['type' => 'string'],
                                'difficulty'   => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $who = $names ? implode(' & ', $names) : 'two teams';
        $system = "You are a trivia generator for a couples/friends party game. 
    Keep everything PG-13, universally appropriate, and free of sensitive content or spoilers for very recent media. 
    Prefer approachable, fun questions. Always include 4 distinct options with one correct answer at index 0-3.";

        $user = trim("
    Category: {$category}
    Difficulty: {$difficulty}
    Audience: {$who}
    Need exactly {$count} multiple-choice questions (4 options each, one correct).
    Write succinct, fair questions. Avoid region-specific knowledge unless globally popular. 
    Return only JSON per schema.
    ");

        $payload = [
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'temperature' => 0.8,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'response_format' => [
                'type'        => 'json_schema',
                'json_schema' => $schema,
            ],
        ];

        $resp = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(45)->post('https://api.openai.com/v1/chat/completions', $payload)
            ->throw()->json();

        $content = $resp['choices'][0]['message']['content'] ?? '{}';
        $parsed  = json_decode($content, true) ?: [];

        // sanitize
        $clean = collect($parsed['questions'] ?? [])
            ->filter(function ($q) {
                return is_array($q)
                    && isset($q['question'], $q['options'], $q['correctIndex'])
                    && is_array($q['options']) && count($q['options']) === 4
                    && $q['correctIndex'] >= 0 && $q['correctIndex'] <= 3;
            })
            ->values()->all();

        $out = ['questions' => $clean];

        if (count($clean) >= $count) {
            Cache::put($key, $out, now()->addHours(6));
        }

        return response()->json($out);
    }

    public function charades(Request $request)
    {
        $v = $request->validate([
            'category'    => 'nullable|string',
            'difficulty'  => 'nullable|in:Easy,Medium,Hard',
            'count'       => 'nullable|integer|min:12|max:80',
            'taboo_words' => 'nullable|integer|min:0|max:5',
            'names'       => 'nullable|array',
            'personalize' => 'nullable|boolean',
        ]);

    $category   = $v['category']   ?? 'General';
    $difficulty = $v['difficulty'] ?? 'Easy';
    $count      = (int)($v['count'] ?? 24);
    $tabooMax   = (int)($v['taboo_words'] ?? 2);
    $names      = $v['names']      ?? null;
    $personal   = (bool)($v['personalize'] ?? false);

    // Cache key (omit names unless personalize=true)
    $key = implode(':', ['charades','v2',strtolower($category),strtolower($difficulty),"n{$count}","t{$tabooMax}"]);
    if ($personal && $names) $key .= ':p:'.substr(sha1(implode('|',$names)), 0, 10);

    if ($cached = Cache::get($key)) {
        return response()->json($cached);
    }

    // --------- Minimal, robust Structured Outputs schema ----------
    $schema = [
        'name'   => 'charades_cards',
        'strict' => true,
        'schema' => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['cards'],
            'properties'           => [
                'cards' => [
                    'type'     => 'array',
                    'minItems' => $count,
                    'maxItems' => $count,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['title','taboo'],
                        'properties'           => [
                            'title'      => ['type' => 'string'],            // acted word/phrase
                            'hint'       => ['type' => 'string'],            // optional (model can emit empty string)
                            'taboo'      => [                                // 0–5 taboo words
                                'type'     => 'array',
                                'minItems' => 0,
                                'maxItems' => 5,                              // static cap (avoids validator quirks)
                                'items'    => ['type' => 'string'],
                            ],
                            'category'   => ['type' => 'string'],
                            'difficulty' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ],
    ];
    // ----------------------------------------------------------------

    $who = $names ? implode(' & ', $names) : 'two teams';
    $system = "You generate PG-13, globally recognizable CHARADES prompts for party play. ".
              "Avoid explicit/offensive content and niche references. ".
              "The 'title' is exactly what the actor must mime. Include 0–5 short taboo words.". "Do not repeat items.";

    $user = "Audience: {$who}\nCategory: {$category}\nDifficulty: {$difficulty}\n".
            "Need exactly {$count} cards. Keep titles short and widely known. Return ONLY JSON.";

    $payload = [
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'temperature' => 0.9,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'response_format' => ['type' => 'json_schema', 'json_schema' => $schema],
    ];

    try {
        $resp = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(45)->post('https://api.openai.com/v1/chat/completions', $payload)
            ->throw()->json();

        $content = $resp['choices'][0]['message']['content'] ?? '{}';
        $parsed  = json_decode($content, true) ?: [];
    } catch (\Illuminate\Http\Client\RequestException $e) {
        // 🔁 Fallback: ask for plain JSON object and parse it ourselves
        $payload['response_format'] = ['type' => 'json_object'];
        $resp2 = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(45)->post('https://api.openai.com/v1/chat/completions', $payload)
            ->throw()->json();
        $content = $resp2['choices'][0]['message']['content'] ?? '{}';
        $parsed  = json_decode($content, true) ?: [];
    }

    // Sanitize
    $cards = collect($parsed['cards'] ?? [])
        ->filter(fn($c) => is_array($c) && isset($c['title']))
        ->map(function ($c) use ($category, $difficulty, $tabooMax) {
            $title = trim(preg_replace('/\s+/', ' ', (string)($c['title'] ?? '')));
            $hint  = isset($c['hint']) ? trim(preg_replace('/\s+/', ' ', (string)$c['hint'])) : '';
            $taboo = array_values(array_unique(array_map('trim', array_slice((array)($c['taboo'] ?? []), 0, 5))));
            return [
                'title'      => $title,
                'hint'       => $hint,
                'taboo'      => $taboo,
                'category'   => (string)($c['category']   ?? $category),
                'difficulty' => (string)($c['difficulty'] ?? $difficulty),
            ];
        })
        ->take($count)
        ->values()
        ->all();

    $out = ['cards' => $cards];

    if (count($cards) >= min(8, $count)) {
        Cache::put($key, $out, now()->addHours(6));
    }

    return response()->json($out);
}


}
