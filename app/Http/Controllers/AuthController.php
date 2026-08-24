<?php

namespace App\Http\Controllers;

use Auth;
use App\Events\PartnerStatusUpdated;
use App\Support\DeviceLabel;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use App\Models\Partner;
use App\Models\User;
use Storage;
class AuthController extends Controller
{
    public function register(Request $r) {
        $v = $r->validate([
            'name' => 'required|string|max:255',
            'email'=> 'required|email|unique:users,email',
            'password' => ['required','confirmed',Password::min(8)],
            'phone'  => 'nullable|string|max:32',
            'gender' => 'nullable|in:Male,Female,Other',
            'dob'    => 'nullable|date',
        ]);
        $user = User::create([
            'name'=>$v['name'],
            'email'=>$v['email'],
            'password'=>Hash::make($v['password']),
            'phone'=>$v['phone'] ?? null,
            'gender'=>$v['gender'] ?? null,
            'dob'=>$v['dob'] ?? null,
        ]);
        $token = $this->issueToken($user, $r);
        return response()->json(['user'=>$user,'token'=>$token], 201);
    }

    public function login(Request $r) {
        $v = $r->validate(['email'=>'required|email','password'=>'required']);
        $user = User::where('email',$v['email'])->first();
        if (!$user || !Hash::check($v['password'], $user->password)) {
            return response()->json(['message'=>'Invalid credentials'], 422);
        }
        if ($user->status === 'deleted') {
            return response()->json(['message'=>'This account has been deleted.'], 403);
        }
        if ($user->status === 'deactivated') {
            return response()->json(['message'=>'Your account has been deactivated. Please contact support.'], 403);
        }
        $token = $this->issueToken($user, $r);
        return response()->json(['user'=>$user,'token'=>$token]);
    }

    private function issueToken(User $user, Request $r): string
    {
        $newToken = $user->createToken('web');
        $newToken->accessToken->forceFill([
            'ip_address' => $r->ip(),
            'user_agent' => substr((string) $r->userAgent(), 0, 255),
        ])->save();

        return $newToken->plainTextToken;
    }

    public function forgotPassword(Request $r) {
        $r->validate(['email' => 'required|email']);

        $status = PasswordBroker::sendResetLink($r->only('email'));

        if ($status === PasswordBroker::RESET_THROTTLED) {
            return response()->json(['message' => __($status)], 429);
        }

        // Always respond with a generic success message, whether or not the
        // email is registered, so requests can't be used to enumerate users.
        return response()->json(['message' => 'If an account exists for that email, a password reset link has been sent.']);
    }

    public function resetPassword(Request $r) {
        $r->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required','confirmed',Password::min(8)],
        ]);

        $status = PasswordBroker::reset(
            $r->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($r) {
                $user->forceFill([
                    'password' => Hash::make($r->string('password')),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => __($status)]);
    }

    public function me(Request $r) {
        $user = Auth::user();
        $partner = $user->activePartner();
        $user->setAttribute('partner', $partner ? [$partner] : []);
        return response()->json($user);
    }
    public function updateUser(Request $r) {
        $v = $r->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone'=> 'nullable|string|max:32',
        ]);
        $r->user()->update($v);
        return response()->json($r->user());
    }
    public function updatePrefs(Request $r) {
        $v = $r->validate([
            'email_news' => 'sometimes|boolean',
            'email_reminders' => 'sometimes|boolean',
            'weekly_summary' => 'sometimes|boolean',
            'private_profile' => 'sometimes|boolean',
        ]);
        $r->user()->update($v);
        return response()->json($r->user());
    }
    public function uploadAvatar(Request $r) {
        $v = $r->validate([
            'avatar' => 'required|image|max:2048', // max 2MB
        ]);
        $path = $v['avatar']->store('avatars','public');
        $user = $r->user();
        $url = \Request::root() . Storage::url($path);
        $user->avatar_url = $url;
        $user->save();
        return response()->json(['url'=>$user->avatar_url]);
    }
    public function changePassword(Request $r) {
        $v = $r->validate([
            'current_password' => 'required',
            'password'     => ['required','confirmed',Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);
        $user = $r->user();
        if (!Hash::check($v['current_password'], $user->password)) {
            return response()->json(['message'=>'Current password is incorrect'], 422);
        }
        $user->password = Hash::make($v['password']);
        $user->save();
        return response()->json(['message'=>'Password changed']);
    }
    public function logout(Request $r) {
        $r->user()->currentAccessToken()->delete();
        return response()->json(['message'=>'Logged out']);
    }

    public function sessions(Request $r) {
        $currentId = $r->user()->currentAccessToken()?->id;

        $sessions = $r->user()->tokens()->orderByDesc('last_used_at')->orderByDesc('created_at')->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'device' => DeviceLabel::parse($t->user_agent),
                'ip_address' => $t->ip_address,
                'last_used_at' => optional($t->last_used_at)->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
                'is_current' => $t->id === $currentId,
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    public function revokeSession(Request $r, int $id) {
        $currentId = $r->user()->currentAccessToken()?->id;
        if ($id === $currentId) {
            return response()->json(['message' => 'Use logout to end your current session'], 422);
        }

        $deleted = $r->user()->tokens()->where('id', $id)->delete();
        abort_unless($deleted, 404);

        return response()->json(['ok' => true]);
    }

    public function logoutOthers(Request $r) {
        $current = $r->user()->currentAccessToken();
        $r->user()->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current->id))->delete();
        return response()->json(['message' => 'Other sessions logged out']);
    }

    public function deleteAccount(Request $r) {
        $v = $r->validate(['password' => 'required']);
        $user = $r->user();

        if (!Hash::check($v['password'], $user->password)) {
            return response()->json(['message' => 'Incorrect password'], 422);
        }
        if ($user->is_admin) {
            return response()->json(['message' => 'Admin accounts cannot be self-deleted'], 422);
        }

        // Soft-close the account: the row (and its game history / referenced
        // data) stays in the database, but it's functionally gone — login()
        // rejects status 'deleted', and every existing token is revoked below.
        $link = Partner::where('status', 'active')
            ->where(fn ($q) => $q->where('user_a_id', $user->id)->orWhere('user_b_id', $user->id))
            ->first();
        if ($link) {
            $otherId = $link->user_a_id === $user->id ? $link->user_b_id : $link->user_a_id;
            $link->update(['status' => 'ended', 'ended_at' => now()]);
            broadcast(new PartnerStatusUpdated($otherId, ['status' => 'ended']));
        }

        $user->tokens()->delete();
        $user->update(['status' => 'deleted', 'deactivated_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
