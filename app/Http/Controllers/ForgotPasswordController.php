<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPasswordMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;

class ForgotPasswordController extends Controller
{
    public function sendPasswordResetEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $token = Str::random(60);
        $user->reset_token = $token;
        $user->save();

        $url = 'https://nearbyu.my.id/User/Resset password.php?id=' . $user->id . '&token=' . $user->reset_token;

        Mail::to($user->email)->send(new ResetPasswordMail($url));

        return response()->json(['message' => 'Reset password link sent to your email'], 200);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'id' => 'required',
            'token' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::find($request->id);

        // Log informasi untuk debugging
        Log::info('User ID:', ['id' => $request->id]);
        Log::info('Reset Token:', ['token' => $request->token]);
        Log::info('Stored Token:', ['stored_token' => $user->reset_token]);

        if (!$user || $user->reset_token !== $request->token) {
            return response()->json(['message' => 'Invalid token or user'], 400);
        }

        $user->password = Hash::make($request->password);
        $user->reset_token = null; 
        $user->save();

        return response()->json(['message' => 'Password has been updated'], 200);
    }
}
