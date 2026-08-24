<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * These 1:1 couple games were seeded without partner_required => true
 * (only truth_dare_erotic had it set), so the app never gated them behind
 * "you need a partner linked first" and — once that gate was added on the
 * frontend — never routed them into the real-time invite/accept flow
 * either. truth_dare_erotic already had the flag and is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('games')
            ->whereIn('kind', ['truth_dare', 'emoji_chat', 'spice_dice', 'memory_match'])
            ->update(['partner_required' => true]);
    }

    public function down(): void
    {
        DB::table('games')
            ->whereIn('kind', ['truth_dare', 'emoji_chat', 'spice_dice', 'memory_match'])
            ->update(['partner_required' => false]);
    }
};
