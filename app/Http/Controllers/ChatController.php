<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Hotel;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Collection;

class ChatController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();
        $message = $request->message;

        // Récupérer les hôtels
        $hotels = Hotel::where('user_id', $user->id)->get();

        // Contexte hôtels
        $hotelContext = $this->buildHotelContext($hotels);

        // Prompt
        $prompt = $this->buildPrompt(
            $message,
            $hotelContext,
            $user->currency ?? 'FCFA'
        );

        // =========================
        // 🔥 API GROK (xAI)
        // =========================
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('XAI_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.x.ai/v1/chat/completions', [
            'model' => 'grok-2-latest',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "Tu es un assistant hôtelier intelligent au Sénégal. 
                    Tu aides les clients à trouver des hôtels selon prix, zone, contact et distance.
                    Réponds de façon claire, simple et professionnelle."
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7
        ]);

        // =========================
        // 🔥 GESTION ERREUR IA
        // =========================
        if ($response->failed()) {
            return $this->fallbackResponse($message, $hotels);
        }

        $data = $response->json();

        $reply = $data['choices'][0]['message']['content'] ?? null;

        if (!$reply) {
            return $this->fallbackResponse($message, $hotels);
        }

        return response()->json([
            'type' => 'text',
            'reply' => $reply
        ]);
    }

    // =========================
    // 🧠 CONTEXTE HÔTELS
    // =========================
    private function buildHotelContext(Collection $hotels): string
    {
        if ($hotels->isEmpty()) {
            return "Aucun hôtel disponible.";
        }

        $text = "Liste des hôtels disponibles :\n";

        foreach ($hotels as $hotel) {
            $text .= "- {$hotel->name}, {$hotel->address}, {$hotel->price} {$hotel->currency}, ";
            $text .= "Téléphone: {$hotel->phone}, Email: {$hotel->email}\n";
        }

        return $text;
    }

    // =========================
    // 🧠 PROMPT IA
    // =========================
    private function buildPrompt(string $message, string $hotelContext, string $currency): string
    {
        return "
Voici les hôtels disponibles :
{$hotelContext}

Devise: {$currency}

Question du client:
{$message}

Instructions:
- Réponds naturellement comme un assistant humain
- Propose des hôtels si nécessaire
- Si aucun résultat, propose des alternatives
- Sois clair et professionnel
";
    }

    // =========================
    // 🔥 FALLBACK (SANS IA)
    // =========================
    private function fallbackResponse(string $message, Collection $hotels): JsonResponse
    {
        $msg = strtolower($message);

        // 🔹 LISTE DES HÔTELS
        if (str_contains($msg, 'hotel') || str_contains($msg, 'hôtel') || str_contains($msg, 'liste')) {
            return response()->json([
                'type' => 'hotels',
                'data' => $hotels
            ]);
        }

        // 🔹 SALUTATION
        if (str_contains($msg, 'bonjour')) {
            return response()->json([
                'type' => 'text',
                'reply' => "👋 Bonjour ! Je peux vous aider à trouver des hôtels par prix, ville ou localisation."
            ]);
        }

        return response()->json([
            'type' => 'text',
            'reply' => "🤖 Assistant indisponible pour le moment. Essayez : 'liste des hôtels'"
        ]);
    }
}