<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Resend\Laravel\Facades\Resend;

class AuthController extends Controller
{
    /**
     * REGISTER - Inscription avec email de bienvenue
     */
    public function register(Request $request): JsonResponse
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

        // Email de bienvenue via Resend
        try {
            $this->sendWelcomeEmail($user);
        } catch (\Exception $e) {
            Log::error('Welcome email error: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Inscription réussie',
            'user' => $user,
            'token' => $token
        ], 201);
    }

    /**
     * LOGIN - Connexion utilisateur
     */
    public function login(Request $request): JsonResponse
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
            'message' => 'Connexion réussie',
            'user' => $user,
            'token' => $token
        ]);
    }

    /**
     * LOGOUT - Déconnexion
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnecté avec succès'
        ]);
    }

    /**
     * ME - Récupérer l'utilisateur connecté
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()
        ]);
    }

    /**
     * UPDATE - Mettre à jour l'utilisateur
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|required|string',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|min:6|confirmed',
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
                $this->sendPasswordChangedEmail($user);
            } catch (\Exception $e) {
                Log::error('Password change email error: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Utilisateur mis à jour',
            'user' => $user
        ]);
    }

    /**
     * DELETE - Supprimer l'utilisateur
     */
    public function delete(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Compte supprimé avec succès'
        ]);
    }

    /**
     * FORGOT PASSWORD - Demande de réinitialisation
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);

        $token = Str::random(60);
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        // Supprimer l'ancien token s'il existe
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Créer le nouveau token
        DB::table('password_reset_tokens')->insert([
            'email' => $request->email,
            'token' => $token,
            'created_at' => now()
        ]);

        $resetLink = "{$frontendUrl}/reset-password?token={$token}&email={$request->email}";

        try {
            $this->sendResetPasswordEmail($request->email, $resetLink);
        } catch (\Exception $e) {
            Log::error('Forgot password email error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'email: ' . $e->getMessage()
            ], 500);
        }

        return response()->json([
            'message' => 'Email de réinitialisation envoyé'
        ]);
    }

    /**
     * RESET PASSWORD - Réinitialisation du mot de passe
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:6|confirmed'
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        // Vérifier si le token existe
        if (!$record) {
            return response()->json([
                'message' => 'Aucune demande de réinitialisation trouvée'
            ], 400);
        }

        // Vérifier si le token correspond
        if ($record->token !== $request->token) {
            return response()->json([
                'message' => 'Token invalide'
            ], 400);
        }

        // Vérifier si le token n'a pas expiré (60 minutes)
        $createdAt = strtotime($record->created_at);
        if (time() - $createdAt > 3600) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'message' => 'Token expiré (plus de 60 minutes)'
            ], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Utilisateur introuvable'
            ], 404);
        }

        // Mettre à jour le mot de passe
        $user->password = Hash::make($request->password);
        $user->save();

        // Supprimer le token utilisé
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        // Supprimer tous les tokens d'authentification de l'utilisateur
        $user->tokens()->delete();

        // Envoyer email de confirmation
        try {
            $this->sendPasswordChangedEmail($user);
        } catch (\Exception $e) {
            Log::error('Password reset confirmation email error: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Mot de passe mis à jour avec succès'
        ]);
    }

    /* ========== MÉTHODES PRIVÉES POUR RESEND ========== */

    /**
     * Envoi email de bienvenue via Resend
     */
    private function sendWelcomeEmail(User $user): void
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        
        $htmlContent = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Bienvenue sur Red Product</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 30px; background: #fff; }
                    .button { display: inline-block; background: #764ba2; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                    .button:hover { background: #5a3a8a; }
                    .footer { background: #f4f4f4; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 10px 10px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🎉 Bienvenue sur Red Product !</h2>
                    </div>
                    <div class='content'>
                        <p>Bonjour <strong>{$user->name}</strong>,</p>
                        <p>Merci d'avoir rejoint <strong>Red Product</strong> ! Nous sommes ravis de vous compter parmi nos utilisateurs.</p>
                        <div style='text-align: center;'>
                            <a href='{$frontendUrl}/dashboard' class='button'>🚀 Accéder à mon espace</a>
                        </div>
                        <hr>
                        <p><strong>Besoin d'aide ?</strong></p>
                        <p>Contactez-nous : <a href='mailto:support@redproduct.com'>support@redproduct.com</a></p>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " Red Product. Tous droits réservés.</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        Resend::emails()->send([
            'from' => env('MAIL_FROM_NAME', 'Red Product') . ' <' . env('MAIL_FROM_ADDRESS', 'onboarding@resend.dev') . '>',
            'to' => $user->email,
            'subject' => '🎉 Bienvenue sur Red Product',
            'html' => $htmlContent,
        ]);
    }

    /**
     * Envoi email de réinitialisation via Resend
     */
    private function sendResetPasswordEmail(string $email, string $resetLink): void
    {
        $user = User::where('email', $email)->first();
        $userName = $user ? $user->name : 'Cher client';

        $htmlContent = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Réinitialisation mot de passe</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 30px; background: #fff; }
                    .button { background: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; }
                    .button:hover { background: #1d4ed8; }
                    .alert { background: #fef3c7; padding: 15px; border-left: 4px solid #f59e0b; margin: 20px 0; }
                    .footer { background: #f4f4f4; padding: 15px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🔐 Réinitialisation du mot de passe</h2>
                    </div>
                    <div class='content'>
                        <p>Bonjour {$userName},</p>
                        <p>Vous avez demandé la réinitialisation de votre mot de passe.</p>
                        <div style='text-align: center;'>
                            <a href='{$resetLink}' class='button'>🔄 Réinitialiser mon mot de passe</a>
                        </div>
                        <div class='alert'>
                            ⏰ Ce lien expire dans <strong>60 minutes</strong>.
                        </div>
                        <p>Si vous n'avez pas demandé cette réinitialisation, ignorez simplement cet email.</p>
                    </div>
                    <div class='footer'>
                        <p>Red Product - Gestion hôtelière professionnelle</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        Resend::emails()->send([
            'from' => env('MAIL_FROM_NAME', 'Red Product') . ' <' . env('MAIL_FROM_ADDRESS', 'onboarding@resend.dev') . '>',
            'to' => $email,
            'subject' => '🔐 Réinitialisation de votre mot de passe',
            'html' => $htmlContent,
        ]);
    }

    /**
     * Envoi email de confirmation de changement de mot de passe via Resend
     */
    private function sendPasswordChangedEmail(User $user): void
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $loginUrl = $frontendUrl . '/login';

        $htmlContent = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Mot de passe modifié</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #10b981; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 30px; background: #fff; }
                    .alert { background: #fef3c7; padding: 15px; border-left: 4px solid #f59e0b; margin: 20px 0; }
                    .button { background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
                    .button:hover { background: #0e9f6e; }
                    .footer { background: #f4f4f4; padding: 15px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>✅ Mot de passe modifié</h2>
                    </div>
                    <div class='content'>
                        <p>Bonjour {$user->name},</p>
                        <div class='alert'>
                            ⚠️ Votre mot de passe a été modifié avec succès.
                        </div>
                        <p>Si vous n'êtes pas à l'origine de cette modification, contactez-nous immédiatement.</p>
                        <div style='text-align: center; margin-top: 30px;'>
                            <a href='{$loginUrl}' class='button'>🔑 Me connecter</a>
                        </div>
                    </div>
                    <div class='footer'>
                        <p>Red Product - Gestion hôtelière</p>
                        <p><a href='mailto:support@redproduct.com'>support@redproduct.com</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        Resend::emails()->send([
            'from' => env('MAIL_FROM_NAME', 'Red Product') . ' <' . env('MAIL_FROM_ADDRESS', 'onboarding@resend.dev') . '>',
            'to' => $user->email,
            'subject' => '🔒 Votre mot de passe a été modifié',
            'html' => $htmlContent,
        ]);
    }
}