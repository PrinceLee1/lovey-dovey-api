<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameAiCacheTest extends TestCase
{
    use RefreshDatabase;

    private function triviaResponse(string $marker, int $count): array
    {
        $questions = [];
        for ($i = 0; $i < $count; $i++) {
            $questions[] = [
                'question' => "{$marker} question {$i}",
                'options' => ['A', 'B', 'C', 'D'],
                'correctIndex' => 0,
                'category' => 'General',
                'difficulty' => 'Medium',
            ];
        }

        return ['choices' => [['message' => ['content' => json_encode(['questions' => $questions])]]]];
    }

    public function test_trivia_rotates_through_three_cached_batches_before_repeating(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'https://api.openai.com/*' => Http::sequence()
                ->push($this->triviaResponse('batch1', 6))
                ->push($this->triviaResponse('batch2', 6))
                ->push($this->triviaResponse('batch3', 6)),
        ]);

        $params = ['category' => 'General', 'difficulty' => 'Medium', 'count' => 6];

        $r1 = $this->actingAs($user, 'sanctum')->postJson('/api/ai/trivia', $params)->assertStatus(200);
        $r2 = $this->actingAs($user, 'sanctum')->postJson('/api/ai/trivia', $params)->assertStatus(200);
        $r3 = $this->actingAs($user, 'sanctum')->postJson('/api/ai/trivia', $params)->assertStatus(200);
        // 4th call should reuse the first cached bucket rather than calling
        // OpenAI a 4th time — proving the same identical set no longer gets
        // served on every single request within the TTL window.
        $r4 = $this->actingAs($user, 'sanctum')->postJson('/api/ai/trivia', $params)->assertStatus(200);

        Http::assertSentCount(3);

        $this->assertStringContainsString('batch1', $r1->json('questions.0.question'));
        $this->assertStringContainsString('batch2', $r2->json('questions.0.question'));
        $this->assertStringContainsString('batch3', $r3->json('questions.0.question'));
        $this->assertSame($r1->json(), $r4->json());
    }
}
