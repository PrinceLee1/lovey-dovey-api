<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\GameHistory;
use Illuminate\Support\Facades\DB;

class AdminMetricsController extends Controller
{
    public function show() {
        $totalUsers   = User::count();
        $activeUsers  = User::where('status','active')->count();
        $deactivated  = User::where('status','deactivated')->count();
        $totalGames   = GameHistory::count();
        $xpSum        = (int) DB::table('users')->sum('xp');

        return response()->json([
            'totals' => compact('totalUsers','activeUsers','deactivated','totalGames','xpSum'),
        ]);
    }
}