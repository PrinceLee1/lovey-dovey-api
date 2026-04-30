<?php
namespace App\Http\Controllers;
 
use App\Models\Games;
use App\Models\Lobby;
use App\Models\Partner;
use App\Models\User;
use App\Models\LobbyGameSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
 
class AdminController extends Controller
{
    public function stats()
    {
        return response()->json([
            'total_users'       => User::count(),
            'active_users'      => User::where('is_active', true)->count(),
            'paired_users'      => Partner::whereNotNull('user_a_id')->whereNotNull('user_b_id')->count(),
            'plus_subscribers'  => User::where('is_plus', true)->count(),
            'total_games_played'=> LobbyGameSession::where('status','ended')->count(),
            'games_today'       => LobbyGameSession::whereDate('created_at', today())->count(),
            'revenue_monthly'   => User::where('is_plus', true)->count() * 4.99,
            'new_users_today'   => User::whereDate('created_at', today())->count(),
            'new_users_week'    => User::whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
            'active_lobbies'    => \App\Models\Lobby::where('status','active')->count(),
            'retention_rate'    => 72, // calculate from your data
        ]);
    }
 
    public function users(Request $request)
    {
        $q = User::with('partner:id,name')->latest();
 
        if ($search = $request->search) {
            $q->where(fn($q) => $q->where('name','like',"%$search%")->orWhere('email','like',"%$search%"));
        }
 
        match($request->filter) {
            'active'     => $q->where('is_active', true),
            'inactive'   => $q->where('is_active', false),
            'plus'       => $q->where('is_plus', true),
            'no_partner' => $q->whereDoesntHave('partner'),
            'admin'      => $q->where('is_admin', true),
            default      => null,
        };
 
        return $q->paginate($request->per_page ?? 20);
    }
 
    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->update($request->only(['is_active','is_admin','is_plus']));
        return response()->json($user);
    }
 
    public function deleteUser($id)
    {
        User::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }
 
    public function bulkDeactivate(Request $request)
    {
        User::whereIn('id', $request->ids)->update(['is_active' => false]);
        return response()->json(['ok' => true]);
    }
 
    public function bulkDelete(Request $request)
    {
        User::whereIn('id', $request->ids)->delete();
        return response()->json(['ok' => true]);
    }
 
    public function reports(Request $request)
    {
        $days = match($request->range) { '7d' => 7, '90d' => 90, default => 30 };
    
        return response()->json([
    
            // ── Signups by day ────────────────────────────────────────────────
            // Fine as-is — grouping by DATE(created_at) alias 'date', ordering by same
            'signups_by_day' => User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy('date')
                ->orderBy('date')  // ORDER BY the alias, not the raw column ✓
                ->get(),
    
            // ── Games by kind ─────────────────────────────────────────────────
            'games_by_kind' => LobbyGameSession::selectRaw('kind, COUNT(*) as count')
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy('kind')
                ->orderByDesc('count')
                ->get(),
    
            // ── Revenue by month ──────────────────────────────────────────────
            // FIX: include the raw DATE_FORMAT expression in both SELECT and GROUP BY
            // so MySQL knows the ORDER BY column is functionally dependent on the group
            'revenue_by_month' => User::selectRaw("
                    DATE_FORMAT(created_at, '%b %Y') as month,
                    DATE_FORMAT(created_at, '%Y%m')  as sort_key,
                    COUNT(*) * 4.99                  as amount
                ")
                ->where('is_plus', true)
                ->where('created_at', '>=', now()->subMonths(6))
                ->groupByRaw("DATE_FORMAT(created_at, '%b %Y'), DATE_FORMAT(created_at, '%Y%m')")
                ->orderByRaw("DATE_FORMAT(created_at, '%Y%m') ASC")  // sort numerically, not by raw created_at
                ->get()
                ->map(fn($r) => ['month' => $r->month, 'amount' => $r->amount]), // strip sort_key from response
    
            // ── Plus conversions ──────────────────────────────────────────────
            // FIX: add sort_key so we can ORDER BY month number, not month name string
            'plus_conversions' => User::selectRaw("
                    DATE_FORMAT(created_at, '%b')  as month,
                    DATE_FORMAT(created_at, '%m')  as sort_key,
                    COUNT(*)                       as count
                ")
                ->where('is_plus', true)
                ->groupByRaw("DATE_FORMAT(created_at, '%b'), DATE_FORMAT(created_at, '%m')")
                ->orderByRaw("DATE_FORMAT(created_at, '%m') ASC")
                ->get()
                ->map(fn($r) => ['month' => $r->month, 'count' => $r->count]),
    
            // ── Top lobbies ───────────────────────────────────────────────────
            'top_lobbies' => Lobby::withCount('sessions')
                ->orderByDesc('sessions_count')
                ->limit(5)
                ->get(['id', 'name', 'code']),
        ]);
    }
 
    public function getSettings()
    {
        // Store settings in a settings table or config
        return response()->json(cache('admin_settings', []));
    }
 
    public function saveSettings(Request $request)
    {
        cache(['admin_settings' => $request->all()], now()->addYear());
        return response()->json(['ok' => true]);
    }
    public function recentSessions()
    {
        return LobbyGameSession::with('lobby:id,name')->where('status','ended')->latest()->limit(20)->get();
    }
    public function allSessions(Request $request)
    {
        $q = LobbyGameSession::with('lobby:id,name')->latest();
        if ($request->code) {
            $q->whereHas('lobby', fn($q) => $q->where('code', $request->code));
        }
        return $q->paginate($request->per_page ?? 20);
    }
    public function deleteGame($id)
    {
        LobbyGameSession::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }
    public function patchGame(Request $request, $id)
    {
        $session = LobbyGameSession::findOrFail($id);
        $session->update($request->only(['status','result','ended_at']));
        return response()->json($session);
    }
    public function updateGame(Request $request, $id)
    {
        $session = LobbyGameSession::findOrFail($id);
        $session->update($request->only(['status','result','ended_at','kind','payload']));
        return response()->json($session);
    }
    public function createGame(Request $request)
    {
        $session = Games::create($request->only(['title','category', 'description','players', 'duration','difficulty','result','kind', 'partner_required']));
        return response()->json($session);
    }
    public function games(Request $request)
    {
        $q = Games::latest();
        if ($request->search) {
            $q->where('title', 'like', "%{$request->search}%");
        }
        return $q->paginate($request->per_page ?? 20);
    }
}
