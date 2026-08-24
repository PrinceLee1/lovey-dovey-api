<?php

namespace App\Support;

class TruthDarePrompts
{
    /** @var array<string,string[]> */
    private const TRUTHS = [
        'romantic' => [
            "What's a small thing I do that makes you fall for me more?",
            "What was your first impression of me?",
            "What's your favorite memory of us together?",
            "What's one thing you'd love for us to do more of?",
            "When did you know you wanted to be with me?",
            "What's something you find irresistibly attractive about me?",
            "What's a dream you have for our future together?",
            "What's the most romantic thing anyone has done for you?",
            "What song reminds you of us?",
            "What's your favorite way for me to show affection?",
            "What's a habit of mine you secretly love?",
            "What made you decide to give this relationship a real chance?",
        ],
        'spicy' => [
            "What's a compliment you love hearing from me?",
            "Where's your favorite place to be kissed?",
            "What's a fantasy you'd feel comfortable sharing with me?",
            "What outfit of mine do you find most attractive?",
            "What's the most spontaneous thing you'd want us to try?",
            "What's a memory of us that still makes you blush?",
            "What's something flirty you've thought but never said?",
            "What's your idea of the perfect romantic night in?",
            "What's a compliment about my body you've never said out loud?",
            "What's something new you'd want to explore together?",
            "What's the boldest thing you'd do to get my attention?",
            "What's a physical touch that instantly gets to you?",
        ],
    ];

    /** @var array<string,string[]> */
    private const DARES = [
        'romantic' => [
            "Give your partner a 30-second shoulder massage.",
            "Write a one-line love note and read it out loud.",
            "Slow dance together for 20 seconds, no music needed.",
            "Give your partner three genuine compliments.",
            "Recreate your first date's opening line.",
            "Hold hands and share your favorite memory of them.",
            "Whisper something sweet in your partner's ear.",
            "Give your partner a forehead kiss.",
            "Plan one sentence of your ideal date night out loud.",
            "Look into each other's eyes for 15 seconds without laughing.",
            "Give your partner a warm, lingering hug.",
            "Tell your partner one thing you're grateful for about them.",
        ],
        'spicy' => [
            "Give your partner a slow kiss on the neck.",
            "Whisper your favorite thing about their body.",
            "Give your partner a 30-second massage anywhere they choose.",
            "Trace a heart on your partner's arm with your finger.",
            "Kiss your partner somewhere unexpected.",
            "Describe, in detail, your favorite moment of intimacy together.",
            "Give your partner a lingering hug from behind.",
            "Slowly remove one item of your partner's accessories (watch, glasses, etc.) with a kiss.",
            "Feed your partner something by hand.",
            "Whisper a flirty compliment in your partner's ear.",
            "Give your partner a long, deep kiss.",
            "Tell your partner exactly what you want to do to them later tonight.",
        ],
    ];

    public static function pool(string $type, string $flavor): array
    {
        $flavor = self::normalizeFlavor($flavor);
        $bank = $type === 'dare' ? self::DARES : self::TRUTHS;

        return $bank[$flavor] ?? $bank['romantic'];
    }

    /**
     * Pick a prompt of the given type, avoiding recently-used ones when possible.
     *
     * @param  string[]  $recentlyUsed
     */
    public static function pick(string $type, string $flavor, array $recentlyUsed = []): string
    {
        $pool = self::pool($type, $flavor);
        $fresh = array_values(array_diff($pool, $recentlyUsed));
        $candidates = $fresh ?: $pool;

        return $candidates[array_rand($candidates)];
    }

    private static function normalizeFlavor(string $kindOrCategory): string
    {
        $v = strtolower($kindOrCategory);

        return str_contains($v, 'erotic') || str_contains($v, 'spicy') || str_contains($v, 'plus')
            ? 'spicy'
            : 'romantic';
    }
}
