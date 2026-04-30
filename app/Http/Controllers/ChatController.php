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

        // 🔥 1. ON DÉTECTE L'INTENTION AVANT GROK
        $filteredHotels = $this->filterHotels($hotels, $message);

        // 🔥 2. SI DEMANDE SIMPLE → PAS BESOIN D'IA
        if ($this->isSimpleRequest($message)) {
            return response()->json([
                'type' => 'hotels',
                'count' => $filteredHotels->count(),
                'data' => $filteredHotels->values()
            ]);
        }

        // 🔥 3. ON ENVOIE À GROK UNIQUEMENT POUR EXPLICATION HUMAINISÉE
        $hotelText = $this->formatForAI($filteredHotels);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('XAI_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.x.ai/v1/chat/completions', [
            'model' => 'grok-2-latest',
            'messages' => [
                [
                    'role' => 'system',
                    'content' =>
                        "Tu es un assistant hôtelier.
                        Tu expliques les résultats de façon simple et humaine.
                        Tu ne dois JAMAIS inventer d'hôtels."
                ],
                [
                    'role' => 'user',
                    'content' =>
                        "QUESTION: {$message}
                        
RESULTATS TROUVÉS:
{$hotelText}

Explique simplement les résultats."
                ]
            ],
            'temperature' => 0.3,
        ]);

        $data = $response->json();

        $reply = $data['choices'][0]['message']['content']
            ?? "Voici les hôtels disponibles.";

        return response()->json([
            'type' => 'text',
            'reply' => $reply,
            'data' => $filteredHotels->values()
        ]);
    }

    // =========================
    // 🔥 FILTRAGE BACKEND (IMPORTANT)
    // =========================
    private function filterHotels(Collection $hotels, string $message): Collection
    {
        $msg = strtolower($message);

        // 🔹 MOINS CHER
        if (str_contains($msg, 'moins cher')) {
            return $hotels->sortBy('price')->take(3);
        }

        // 🔹 PLUS CHER
        if (str_contains($msg, 'plus cher')) {
            return $hotels->sortByDesc('price')->take(3);
        }

        // 🔹 PRIX EXACT
        if (preg_match('/(\d+)/', $msg, $m)) {
            $price = (int) $m[1];
            return $hotels->where('price', $price);
        }

        return $hotels;
    }

    // =========================
    // 🔥 DÉTECTION SIMPLE
    // =========================
    private function isSimpleRequest(string $message): bool
    {
        $msg = strtolower($message);

        return str_contains($msg, 'liste')
            || str_contains($msg, 'tous')
            || str_contains($msg, 'moins cher');
    }

    // =========================
    // 🔥 FORMAT POUR GROK
    // =========================
    private function formatForAI(Collection $hotels): string
    {
        if ($hotels->isEmpty()) {
            return "Aucun hôtel trouvé.";
        }

        $text = "";

        foreach ($hotels as $h) {
            $text .= "- {$h->name}, {$h->price} FCFA, {$h->address}\n";
        }

        return $text;
    }
}