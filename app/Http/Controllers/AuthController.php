<?php

namespace App\Http\Controllers;

use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
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
        $token = $user->createToken('web')->plainTextToken;
        return response()->json(['user'=>$user,'token'=>$token], 201);
    }

    public function login(Request $r) {
        $v = $r->validate(['email'=>'required|email','password'=>'required']);
        $user = User::where('email',$v['email'])->first();
        if (!$user || !Hash::check($v['password'], $user->password)) {
            return response()->json(['message'=>'Invalid credentials'], 422);
        }
        $token = $user->createToken('web')->plainTextToken;
        return response()->json(['user'=>$user,'token'=>$token]);
    }

    public function me(Request $r) {
        $user = Auth::user();
        $user->load('partner');
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
}
