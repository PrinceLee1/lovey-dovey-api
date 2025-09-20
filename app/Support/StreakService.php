<?php
// app/Support/StreakService.php
namespace App\Support;

use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;

class StreakService
{
    /** Get the user's “today” date in their timezone (as YYYY-MM-DD). */
    public static function todayFor(User $u): string
    {
        return CarbonImmutable::now($u->timezone ?: 'UTC')->toDateString();
    }

    /** Bump a user's streak for their “today” (safe to call multiple times/day). */
    public static function bumpUser(User $u): void
    {
        $today = self::todayFor($u);
        $last  = $u->streak_updated_for_date?->toDateString();

        if ($last === $today) {
            return; // already counted today
        }

        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $current   = ($last === $yesterday) ? ($u->streak_current + 1) : 1;
        $longest   = max($u->streak_longest, $current);

        $u->forceFill([
            'streak_current'            => $current,
            'streak_longest'            => $longest,
            'streak_updated_for_date'   => $today,
        ])->save();
    }

    /**
     * Bump couple streak on the Partner row (uses UTC date by default).
     * You can change to a pairing TZ strategy later if needed.
     */
    public static function bumpCouple(Partner $pair): void
    {
        $today = CarbonImmutable::now('UTC')->toDateString();
        $last  = optional($pair->couple_streak_updated_for_date)->toDateString();

        if ($last === $today) return;

        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $current   = ($last === $yesterday) ? ($pair->couple_streak_current + 1) : 1;
        $longest   = max($pair->couple_streak_longest, $current);

        $pair->forceFill([
            'couple_streak_current'            => $current,
            'couple_streak_longest'            => $longest,
            'couple_streak_updated_for_date'   => $today,
        ])->save();
    }
}
