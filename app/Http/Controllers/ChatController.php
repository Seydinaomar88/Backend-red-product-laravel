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

        // Transformer les hôtels en texte pour l’IA
        $hotelContext = $this->buildHotelContext($hotels);

        // Prompt intelligent
        $prompt = $this->buildPrompt($message, $hotelContext, $user->currency ?? 'FCFA');

        // Appel API IA (OpenAI / Grok style)
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "Tu es un assistant hôtelier intelligent au Sénégal. 
                    Tu aides les clients à trouver des hôtels selon prix, zone, contact et distance.
                    Sois clair, professionnel et amical."
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7
        ]);

        $reply = $response['choices'][0]['message']['content'] ?? "Erreur IA.";

        return response()->json([
            'type' => 'text',
            'reply' => $reply
        ]);
    }

    /**
     * 🔹 Construire contexte hôtels pour l’IA
     */
   

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

    /**
     * 🔹 Construire prompt intelligent
     */
    private function buildPrompt(string $message, string $hotelContext, string $currency): string
    {
        return "
        Voici les hôtels disponibles :
        {$hotelContext}

        Devise: {$currency}

        Question du client:
        {$message}

        Instructions:
        - Réponds clairement
        - Propose des hôtels si nécessaire
        - Si aucun résultat, suggère des alternatives
        - Sois naturel comme un assistant humain
        ";
    }
}