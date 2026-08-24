<?php

namespace Tests\Unit;

use App\Support\TruthDarePrompts;
use PHPUnit\Framework\TestCase;

class TruthDarePromptsTest extends TestCase
{
    public function test_spice_dice_kind_pulls_from_the_spicy_pool(): void
    {
        // "spice_dice" contains "spice", not "spicy" — a substring check for
        // the latter alone silently missed it and fell back to the romantic
        // pool. Assert every dare it can draw is actually from the spicy pool.
        $spicyPool = TruthDarePrompts::pool('dare', 'spice_dice');
        $romanticPool = TruthDarePrompts::pool('dare', 'truth_dare');

        for ($i = 0; $i < 20; $i++) {
            $prompt = TruthDarePrompts::pick('dare', 'spice_dice', []);
            $this->assertContains($prompt, $spicyPool);
            $this->assertNotContains($prompt, $romanticPool);
        }
    }

    public function test_truth_dare_erotic_kind_pulls_from_the_spicy_pool(): void
    {
        $spicyPool = TruthDarePrompts::pool('truth', 'truth_dare_erotic');
        $prompt = TruthDarePrompts::pick('truth', 'truth_dare_erotic', []);
        $this->assertContains($prompt, $spicyPool);
    }

    public function test_truth_dare_kind_pulls_from_the_romantic_pool(): void
    {
        $romanticPool = TruthDarePrompts::pool('truth', 'truth_dare');
        $prompt = TruthDarePrompts::pick('truth', 'truth_dare', []);
        $this->assertContains($prompt, $romanticPool);
    }

    public function test_pick_avoids_recently_used_prompts_when_possible(): void
    {
        $pool = TruthDarePrompts::pool('truth', 'truth_dare');
        $used = array_slice($pool, 0, count($pool) - 1);

        $prompt = TruthDarePrompts::pick('truth', 'truth_dare', $used);

        $this->assertSame($pool[count($pool) - 1], $prompt);
    }
}
