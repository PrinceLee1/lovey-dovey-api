<?php

namespace App\Http\Controllers;

use App\Models\Games;
use Illuminate\Http\Request;

class GameController extends Controller
{
        // For dashboard list
    public function index()
    {
        $games = Games::orderBy('category')
            ->orderBy('title')
            ->get([
                'id',
                'kind',
                'title',
                'category',
                'description',
                'players',
                'duration',
                'difficulty',
                'partner_required',
            ]);

        $categories = $games
            ->pluck('category')
            ->unique()
            ->values();

        return response()->json([
            'categories' => $categories,
            'games' => $games,
        ]);
    }

    // For GameRunner
    public function show(Games $game)
    {
        $game->load(['prompts' => function ($q) {
            $q->orderBy('level')->inRandomOrder();
        }]);

        return response()->json($game);
    }
}
