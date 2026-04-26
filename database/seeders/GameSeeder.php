<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        $games = [
            [
                'kind' => 'truth_dare',
                'title' => 'Truth or Dare – Romantic',
                'category' => 'Romantic',
                'description' => 'Sweet prompts to spark intimacy and laughter.',
                'duration' => 10,
                'players' => 2,
                'difficulty' => 'Easy',
            ],
            [
                'kind' => 'emoji_chat',
                'title' => 'Emoji-Only Chat',
                'category' => 'Playful',
                'description' => 'Speak only in emojis for 5 minutes. Guess the message!',
                'duration' => 5,
                'players' => 2,
                'difficulty' => 'Easy',
            ],
            [
                'kind' => 'spice_dice',
                'title' => 'Spice Dice',
                'category' => 'Spicy',
                'description' => 'Roll the dice for a daring prompt. Keep it fun and consensual.',
                'duration' => 8,
                'players' => 2,
                'difficulty' => 'Medium',
            ],
            [
                'kind' => 'memory_match',
                'title' => 'Memory Match – Couple Edition',
                'category' => 'Challenge',
                'description' => 'Test how well you remember each other\'s favorites.',
                'duration' => 7,
                'players' => 2,
                'difficulty' => 'Medium',
            ],
            [
                'kind' => 'trivia',
                'title' => 'Trivia Night: Duo vs Duo',
                'category' => 'Challenge',
                'description' => 'Team up for trivia madness in group mode.',
                'duration' => 12,
                'players' => 4,
                'difficulty' => 'Hard',
            ],
            [
                'kind' => 'charades_ai',
                'title' => 'Charades with AI Prompts',
                'category' => 'Playful',
                'description' => 'Act it out, let the group guess!',
                'duration' => 10,
                'players' => 3,
                'difficulty' => 'Easy',
            ],
            [
                'kind' => 'truth_dare_erotic',
                'title' => 'Truth or Dare – Erotic',
                'category' => 'Erotic',
                'description' => 'Intimate, bold and playful prompts for couples only.',
                'duration' => 10,
                'players' => 2,
                'difficulty' => 'Hard',
                'partner_required' => true,
            ],
        ];

        foreach ($games as $game) {
            DB::table('games')->updateOrInsert(
                ['title' => $game['title']],
                array_merge($game, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}