<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use App\Models\User;
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

    public function me(Request $r) { return response()->json($r->user()); }

    public function logout(Request $r) {
        $r->user()->currentAccessToken()->delete();
        return response()->json(['message'=>'Logged out']);
    }
}
