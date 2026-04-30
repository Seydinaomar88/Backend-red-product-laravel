<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Hotel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Point d'entrée principal du chat - Assistant IA avec Grok
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
            'city' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $message = $request->message;
        $userId = $request->user()->id;
        $userCurrency = $request->user()->currency ?? 'FCFA';

        // Récupérer les hôtels de l'utilisateur
        $hotels = Hotel::where('user_id', $userId)->get();

        // Appeler l'API Grok pour analyser la demande
        $grokResponse = $this->callGrokAPI($message, $hotels, $userCurrency);

        return response()->json($grokResponse);
    }

    /**
     * Appel à l'API Grok (xAI)
     */
    private function callGrokAPI(string $message, Collection $hotels, string $currency): array
    {
        $apiKey = env('XAI_API_KEY');
        
        if (!$apiKey) {
            Log::error('XAI_API_KEY manquante');
            return $this->fallbackResponse($message, $hotels, $currency);
        }

        // Formater les données des hôtels pour Grok
        $hotelsData = $hotels->map(function ($hotel) {
            return [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'address' => $hotel->address,
                'price' => $hotel->price,
                'currency' => $hotel->currency,
                'description' => $hotel->description ?? '',
                'phone' => $hotel->phone ?? '',
                'email' => $hotel->email ?? '',
            ];
        })->toArray();

        // Construction du prompt pour Grok
        $systemPrompt = "Tu es un assistant hôtelier professionnel. Tu aides les clients à trouver des hôtels.

Voici la liste des hôtels disponibles (au format JSON) :
" . json_encode($hotelsData, JSON_PRETTY_PRINT) . "

Règles importantes :
1. Réponds de manière naturelle et conviviale
2. Si l'utilisateur cherche un hôtel par nom, prix, adresse ou contact, filtre les résultats
3. Si plusieurs hôtels correspondent, présente-les de façon claire
4. Si aucun hôtel ne correspond, propose des alternatives ou demande plus d'informations
5. Utilise des emojis pour rendre la réponse plus chaleureuse
6. Retourne une réponse au format JSON avec les clés : 'type' (text ou hotels), 'message' (le texte de réponse), et 'data' (liste des hôtels si nécessaire)

Exemple de réponse pour des hôtels :
{\"type\":\"hotels\",\"message\":\"Voici 2 hôtels correspondant à votre recherche :\",\"data\":[{\"id\":1,\"name\":\"Hotel Rade\"},{\"id\":2,\"name\":\"Hotel Terrou\"}]}

Exemple de réponse texte :
{\"type\":\"text\",\"reply\":\"Bonjour ! Comment puis-je vous aider ?\"}";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.x.ai/v1/chat/completions', [
                'model' => 'grok-beta', // ou 'grok-2-latest'
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt
                    ],
                    [
                        'role' => 'user',
                        'content' => "Message utilisateur : " . $message . "\nDevise: " . $currency
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 1500,
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'];
                
                // Nettoyer le contenu (enlever les markdown JSON si présent)
                $content = trim($content);
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                
                $result = json_decode($content, true);
                
                // Vérifier si le JSON est valide
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('Invalid JSON from Grok', ['content' => $content]);
                    return $this->fallbackResponse($message, $hotels, $currency);
                }
                
                // Si Grok retourne des hôtels, on les enrichit avec les données complètes
                if (isset($result['type']) && $result['type'] === 'hotels' && isset($result['data'])) {
                    $enrichedHotels = [];
                    foreach ($result['data'] as $hotelData) {
                        $hotel = $hotels->firstWhere('id', $hotelData['id']);
                        if ($hotel) {
                            $enrichedHotels[] = [
                                'id' => $hotel->id,
                                'name' => $hotel->name,
                                'address' => $hotel->address,
                                'price' => $hotel->price,
                                'currency' => $hotel->currency,
                                'image' => $hotel->image,
                                'phone' => $hotel->phone ?? 'Non renseigné',
                                'email' => $hotel->email ?? 'Non renseigné',
                            ];
                        }
                    }
                    $result['data'] = $enrichedHotels;
                    $result['count'] = count($enrichedHotels);
                }
                
                Log::info('Grok API appelée avec succès', ['message' => $message]);
                return $result;
            }
            
            Log::error('Erreur Grok API', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return $this->fallbackResponse($message, $hotels, $currency);
            
        } catch (\Exception $e) {
            Log::error('Exception Grok API: ' . $e->getMessage());
            return $this->fallbackResponse($message, $hotels, $currency);
        }
    }

    /**
     * Réponse de secours en cas d'échec de l'API Grok
     */
    private function fallbackResponse(string $message, Collection $hotels, string $currency): array
    {
        $searchTerm = strtolower($message);
        
        // Extraction des prix
        preg_match_all('/(\d+)/', $message, $priceMatches);
        $prices = $priceMatches[0] ?? [];
        
        $filteredHotels = $hotels;
        
        // Filtrage par prix
        if (!empty($prices)) {
            if (str_contains($message, 'moins') || str_contains($message, 'max')) {
                $filteredHotels = $filteredHotels->where('price', '<=', (int)$prices[0]);
            } elseif (str_contains($message, 'plus') || str_contains($message, 'min')) {
                $filteredHotels = $filteredHotels->where('price', '>=', (int)$prices[0]);
            } elseif (str_contains($message, 'entre') && count($prices) >= 2) {
                $filteredHotels = $filteredHotels->whereBetween('price', [(int)$prices[0], (int)$prices[1]]);
            } else {
                $filteredHotels = $filteredHotels->where('price', (int)$prices[0]);
            }
        }
        
        // Filtrage par zone
        $zones = ['dakar', 'ngor', 'almadie', 'plateau', 'yoff', 'saly', 'mbour'];
        foreach ($zones as $zone) {
            if (str_contains($searchTerm, $zone)) {
                $filteredHotels = $filteredHotels->filter(function ($hotel) use ($zone) {
                    return str_contains(strtolower($hotel->address), $zone);
                });
                break;
            }
        }
        
        // Filtrage par nom
        $hotelNames = ['rade', 'terrou', 'radisson', 'king fahd', 'pullman'];
        foreach ($hotelNames as $name) {
            if (str_contains($searchTerm, $name)) {
                $filteredHotels = $filteredHotels->filter(function ($hotel) use ($name) {
                    return str_contains(strtolower($hotel->name), $name);
                });
                break;
            }
        }
        
        if ($filteredHotels->isEmpty()) {
            return [
                'type' => 'text',
                'reply' => "🔍 Je ne trouve pas d'hôtel correspondant à votre recherche. Pouvez-vous me donner plus de détails (nom, quartier, budget) ?"
            ];
        }
        
        return [
            'type' => 'hotels',
            'count' => $filteredHotels->count(),
            'message' => "🏨 Voici les hôtels trouvés :",
            'data' => $filteredHotels->map(function ($hotel) {
                return [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                    'address' => $hotel->address,
                    'price' => $hotel->price,
                    'currency' => $hotel->currency,
                    'image' => $hotel->image,
                    'phone' => $hotel->phone ?? 'Non renseigné',
                    'email' => $hotel->email ?? 'Non renseigné',
                ];
            })->values()
        ];
    }
}