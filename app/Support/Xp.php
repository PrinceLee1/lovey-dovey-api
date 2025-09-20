<?php
// app/Support/Xp.php
namespace App\Support;

class Xp
{
    // XP needed to go from (level) -> (level+1)
    public static function xpToNext(int $level): int
    {
        // base 200, grows ~15% per level
        return (int) round(200 * pow(1.15, max(0, $level - 1)));
    }

    // Given total XP, return [level, xpIntoLevel, xpNeeded]
    public static function breakdown(int $total): array
    {
        $level = 1;
        $remaining = $total;
        for (;; $level++) {
            $need = self::xpToNext($level);
            if ($remaining < $need) {
                return [$level, $remaining, $need];
            }
            $remaining -= $need;
        }
    }
}
