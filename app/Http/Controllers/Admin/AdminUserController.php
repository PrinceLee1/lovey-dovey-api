<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $r) {
        $q = User::query();

        if ($s = $r->query('status')) {
            $q->where('status', $s);
        }
        if ($query = trim((string)$r->query('query'))) {
            $q->where(function($w) use ($query){
                $w->where('name','like',"%$query%")
                  ->orWhere('email','like',"%$query%");
            });
        }

        $sort = in_array($r->query('sort'), ['name','email','created_at','xp','status']) ? $r->query('sort') : 'created_at';
        $dir  = $r->query('dir') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sort, $dir);

        $users = $q->paginate(15)->appends($r->query());
        return UserResource::collection($users);
    }

    // PATCH /admin/users/{id}/status { action: "deactivate" | "reactivate" }
    public function updateStatus(Request $r, User $user) {
        $data = $r->validate([
            'action' => 'required|in:deactivate,reactivate',
        ]);

        if ($user->is_admin && $data['action'] == 'deactivate') {
            return response()->json(['message'=>'Cannot deactivate another admin'], 422);
        }

        if ($data['action'] == 'deactivate') {
            $user->update(['status'=>'deactivated','deactivated_at'=>now()]);
        } else {
            $user->update(['status'=>'active','deactivated_at'=>null]);
        }
        return new UserResource($user->fresh());
    }

    // DELETE /admin/users/{id}
    public function destroy(User $user) {
        if ($user->is_admin) {
            return response()->json(['message'=>'Cannot delete admin'], 422);
        }
        $user->delete(); // soft delete
        return response()->json(['ok'=>true]);
    }
}
