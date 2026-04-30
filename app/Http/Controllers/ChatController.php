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
        $message = trim($request->message);
        $currency = $user->currency ?? 'FCFA';

        $hotels = Hotel::where('user_id', $user->id)->get();

        $hotelContext = $this->buildHotelContext($hotels);

        $prompt = $this->buildPrompt($message, $hotelContext, $currency);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('XAI_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.x.ai/v1/chat/completions', [
            'model' => 'grok-2-latest',
            'messages' => [
                [
                    'role' => 'system',
                    'content' =>
                        "Tu es un assistant hôtelier intelligent.

RÈGLES IMPORTANTES :
- Tu ne dois utiliser QUE les hôtels fournis
- Ne jamais inventer d'hôtels
- Si aucun hôtel ne correspond, dis 'aucun hôtel trouvé'
- Toujours répondre clairement et simplement
- Si prix demandé, trie les hôtels du moins cher au plus cher
- Si demande 'moins cher', donne les 3 moins chers
- Réponse courte, claire, professionnelle"
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.3, // 🔥 IMPORTANT : moins d'hallucination
        ]);

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
    // 🧠 CONTEXTE HÔTELS (PROPRE)
    // =========================
    private function buildHotelContext(Collection $hotels): string
    {
        if ($hotels->isEmpty()) {
            return "Aucun hôtel disponible.";
        }

        $text = "";

        foreach ($hotels as $hotel) {
            $text .= "ID: {$hotel->id} | ";
            $text .= "Nom: {$hotel->name} | ";
            $text .= "Adresse: {$hotel->address} | ";
            $text .= "Prix: {$hotel->price} FCFA | ";
            $text .= "Tel: {$hotel->phone}\n";
        }

        return $text;
    }

    // =========================
    // 🧠 PROMPT OPTIMISÉ
    // =========================
    private function buildPrompt(string $message, string $hotelContext, string $currency): string
    {
        return "
HÔTELS DISPONIBLES:
{$hotelContext}

MONNAIE: {$currency}

QUESTION CLIENT:
{$message}

INSTRUCTIONS:
- Réponds uniquement avec les hôtels fournis
- Si prix demandé → filtre correctement
- Si 'moins cher' → trie par prix
- Si rien trouvé → dis clairement 'aucun hôtel trouvé'
- Réponse courte et utile
";
    }

    // =========================
    // 🔥 FALLBACK FIABLE
    // =========================
    private function fallbackResponse(string $message, Collection $hotels): JsonResponse
    {
        $msg = strtolower($message);

        if (str_contains($msg, 'liste') || str_contains($msg, 'hotel') || str_contains($msg, 'hôtel')) {
            return response()->json([
                'type' => 'hotels',
                'count' => $hotels->count(),
                'data' => $hotels->values()
            ]);
        }

        if (str_contains($msg, 'moins cher')) {
            return response()->json([
                'type' => 'hotels',
                'data' => $hotels->sortBy('price')->take(3)->values()
            ]);
        }

        if (str_contains($msg, 'bonjour')) {
            return response()->json([
                'type' => 'text',
                'reply' => "👋 Bonjour ! Je peux vous aider à trouver un hôtel selon votre budget ou localisation."
            ]);
        }

        return response()->json([
            'type' => 'text',
            'reply' => "🤖 Je n'ai pas compris. Essayez : 'liste des hôtels' ou 'moins cher'"
        ]);
    }
}