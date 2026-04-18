<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Mail\WelcomeMail;
use App\Mail\PasswordChangedMail;

class AuthController extends Controller
{
    /* REGISTER*/
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
        } catch (\Exception $e) {
            Log::error('Welcome email error: ' . $e->getMessage());
        }

        return response()->json([
            'user' => $user,
            'token' => $token
        ], 201);
    }

    /* LOGIN*/
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Email ou mot de passe incorrect'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token
        ]);
    }

    /* LOGOUT*/
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Déconnecté avec succès'
        ]);
    }

    /*ME*/
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()
        ]);
    }

    /* UPDATE USER*/
    public function update(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|required|string',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|min:6',
        ]);

        $passwordChanged = false;

        if ($request->has('name')) {
            $user->name = $request->name;
        }

        if ($request->has('email')) {
            $user->email = $request->email;
        }

        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
            $passwordChanged = true;
        }

        $user->save();

        if ($passwordChanged) {
            try {
                Mail::to($user->email)->send(new PasswordChangedMail($user));
            } catch (\Exception $e) {
                Log::error('Password change email error: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Utilisateur mis à jour',
            'user' => $user
        ]);
    }

    /* DELETE USER*/
    public function delete(Request $request)
    {
        $user = $request->user();

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Compte supprimé avec succès'
        ]);
    }

    /*FORGOT PASSWORD (FIXED)*/
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'email' => $request->email,
                'token' => Hash::make($token),
                'created_at' => now()
            ]
        );

        $link = "http://localhost:3000/reset-password?token=$token&email={$request->email}";

        try {
            Mail::raw(
                "Clique ici pour réinitialiser ton mot de passe : $link",
                function ($message) use ($request) {
                    $message->to($request->email)
                        ->subject('Reset Password');
                }
            );
        } catch (\Exception $e) {
            Log::error('Forgot password email error: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Email de réinitialisation envoyé'
        ]);
    }

    /*RESET PASSWORD (FIXED)*/
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:6'
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json([
                'message' => 'Token invalide ou expiré'
            ], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Utilisateur introuvable'
            ], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'message' => 'Mot de passe mis à jour avec succès'
        ]);
    }
}