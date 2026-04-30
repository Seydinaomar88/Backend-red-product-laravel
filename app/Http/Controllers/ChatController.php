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

        // Construction du prompt pour Grok avec instructions précises
        $systemPrompt = "Tu es un assistant hôtelier professionnel. Tu aides les clients à trouver des hôtels.

Voici la liste des hôtels disponibles (au format JSON) :
" . json_encode($hotelsData, JSON_PRETTY_PRINT) . "

RÈGLES TRÈS IMPORTANTES À RESPECTER ABSOLUMENT :

1. **PRIX MOINS CHER** : Si l'utilisateur dit 'moins cher', 'pas cher', 'économique', 'budget', tu DOIS filtrer et retourner UNIQUEMENT les hôtels avec les prix les plus bas (trier par prix croissant et ne garder que les 3 premiers).

2. **PRIX PLUS CHER** : Si l'utilisateur dit 'plus cher', 'luxe', 'haut de gamme', tu DOIS filtrer et retourner UNIQUEMENT les hôtels avec les prix les plus élevés (trier par prix décroissant et ne garder que les 3 premiers).

3. **RECHERCHE PRÉCISE** : 
   - 'à 15000' → prix EXACTEMENT 15000
   - 'moins de 30000' → prix ≤ 30000
   - 'plus de 50000' → prix ≥ 50000
   - 'entre 20000 et 50000' → prix entre ces valeurs

4. **PAR QUARTIER** : Si l'utilisateur donne un quartier (Dakar, Ngor, Saly...), retourne UNIQUEMENT les hôtels dans ce quartier.

5. **PAR NOM** : Si l'utilisateur donne un nom d'hôtel, retourne UNIQUEMENT cet hôtel.

6. **RÉPONSE JSON** : Tu dois retourner UNIQUEMENT du JSON valide, pas de texte avant ou après.

Exemples de réponse JSON :

Pour 'hôtel moins cher' :
{\"type\":\"hotels\",\"message\":\"🏨 Voici les 3 hôtels les moins chers :\",\"data\":[{\"id\":5,\"name\":\"Hotel Pas Cher\",\"price\":15000},{\"id\":3,\"name\":\"Hotel Budget\",\"price\":20000},{\"id\":1,\"name\":\"Hotel Moyen\",\"price\":25000}]}

Pour 'hôtel à 25000' (prix exact) :
{\"type\":\"hotels\",\"message\":\"🏨 Hôtel trouvé à 25000 FCFA :\",\"data\":[{\"id\":2,\"name\":\"Hotel Exact\",\"price\":25000}]}

Pour 'hôtel à Dakar' :
{\"type\":\"hotels\",\"message\":\"📍 Voici les hôtels situés à Dakar :\",\"data\":[{\"id\":1,\"name\":\"Hotel Dakar\",\"address\":\"Dakar\"}]}

Pour aucun résultat :
{\"type\":\"text\",\"reply\":\"😕 Désolé, aucun hôtel ne correspond à votre recherche. Essayez avec un autre budget ou quartier.\"}";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.x.ai/v1/chat/completions', [
                'model' => 'grok-beta',
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
                'temperature' => 0.3,  // Plus bas pour des réponses plus précises
                'max_tokens' => 1500,
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'];
                
                // Nettoyer le contenu
                $content = trim($content);
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                
                $result = json_decode($content, true);
                
                // Vérifier si le JSON est valide
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('Invalid JSON from Grok', ['content' => $content]);
                    return $this->fallbackResponse($message, $hotels, $currency);
                }
                
                // Si Grok retourne des hôtels, on les enrichit
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
     * Réponse de secours avec recherches PRÉCISES (sans IA)
     */
    private function fallbackResponse(string $message, Collection $hotels, string $currency): array
    {
        $searchTerm = strtolower($message);
        $filteredHotels = $hotels;
        $sortDirection = null; // 'asc' pour moins cher, 'desc' pour plus cher
        
        // === DÉTECTION "MOINS CHER" ===
        if (str_contains($searchTerm, 'moins cher') || 
            str_contains($searchTerm, 'pas cher') || 
            str_contains($searchTerm, 'économique') ||
            str_contains($searchTerm, 'budget') ||
            str_contains($searchTerm, 'le moins cher')) {
            $filteredHotels = $hotels->sortBy('price');
            $sortDirection = 'asc';
            // Limiter aux 3 moins chers
            $filteredHotels = $filteredHotels->take(3);
            $messagePrefix = "🏨 Voici les {$filteredHotels->count()} hôtels les moins chers :";
        }
        
        // === DÉTECTION "PLUS CHER" ===
        elseif (str_contains($searchTerm, 'plus cher') || 
                str_contains($searchTerm, 'luxe') || 
                str_contains($searchTerm, 'haut de gamme') ||
                str_contains($searchTerm, 'le plus cher')) {
            $filteredHotels = $hotels->sortByDesc('price');
            $sortDirection = 'desc';
            // Limiter aux 3 plus chers
            $filteredHotels = $filteredHotels->take(3);
            $messagePrefix = "🏨 Voici les {$filteredHotels->count()} hôtels les plus chers :";
        }
        
        // === EXTRACTION DES PRIX ===
        preg_match_all('/(\d+)/', $message, $priceMatches);
        $prices = $priceMatches[0] ?? [];
        
        if (!empty($prices) && !$sortDirection) {
            // Prix exact (ex: "hôtel à 15000")
            if (str_contains($message, ' à ') || str_contains($message, ' exact')) {
                $filteredHotels = $hotels->where('price', (int)$prices[0]);
                $messagePrefix = "🏨 Hôtel(s) à exactement {$prices[0]} {$currency} :";
            }
            // Moins de X
            elseif (str_contains($message, 'moins') || str_contains($message, 'max') || str_contains($message, '<')) {
                $filteredHotels = $hotels->where('price', '<=', (int)$prices[0]);
                $messagePrefix = "🏨 Hôtels à moins de {$prices[0]} {$currency} :";
            }
            // Plus de X
            elseif (str_contains($message, 'plus') || str_contains($message, 'min') || str_contains($message, '>')) {
                $filteredHotels = $hotels->where('price', '>=', (int)$prices[0]);
                $messagePrefix = "🏨 Hôtels à plus de {$prices[0]} {$currency} :";
            }
            // Entre X et Y
            elseif (str_contains($message, 'entre') && count($prices) >= 2) {
                $filteredHotels = $hotels->whereBetween('price', [(int)$prices[0], (int)$prices[1]]);
                $messagePrefix = "🏨 Hôtels entre {$prices[0]} et {$prices[1]} {$currency} :";
            }
            // Simple chiffre
            elseif (count($prices) == 1) {
                $filteredHotels = $hotels->where('price', (int)$prices[0]);
                $messagePrefix = "🏨 Hôtel(s) à {$prices[0]} {$currency} :";
            }
        }
        
        // === FILTRAGE PAR ZONE (si pas déjà filtré) ===
        if (!$sortDirection && $filteredHotels == $hotels) {
            $zones = [
                'dakar' => 'Dakar', 'ngor' => 'Ngor', 'almadie' => 'Almadies',
                'plateau' => 'Plateau', 'yoff' => 'Yoff', 'ouakam' => 'Ouakam',
                'mermoz' => 'Mermoz', 'saly' => 'Saly', 'mbour' => 'Mbour',
                'la somone' => 'La Somone', 'lac rose' => 'Lac Rose'
            ];
            
            foreach ($zones as $zoneKey => $zoneName) {
                if (str_contains($searchTerm, $zoneKey)) {
                    $filteredHotels = $hotels->filter(function ($hotel) use ($zoneName) {
                        return str_contains(strtolower($hotel->address), strtolower($zoneName));
                    });
                    $messagePrefix = "📍 Hôtels situés à {$zoneName} :";
                    break;
                }
            }
        }
        
        // === FILTRAGE PAR NOM ===
        if (!$sortDirection && $filteredHotels == $hotels) {
            $hotelNames = ['rade', 'terrou', 'radisson', 'king fahd', 'pullman', 'meridien'];
            foreach ($hotelNames as $name) {
                if (str_contains($searchTerm, $name)) {
                    $filteredHotels = $hotels->filter(function ($hotel) use ($name) {
                        return str_contains(strtolower($hotel->name), $name);
                    });
                    $messagePrefix = "🏨 Hôtel(s) correspondant à votre recherche :";
                    break;
                }
            }
        }
        
        // === SI AUCUN FILTRE N'A ÉTÉ APPLIQUÉ ===
        if ($filteredHotels == $hotels) {
            return [
                'type' => 'text',
                'reply' => "🔍 Je peux vous aider à trouver des hôtels !\n\n" .
                           "Essayez :\n" .
                           "• 'hôtel moins cher' - pour les meilleurs prix\n" .
                           "• 'hôtel à 25000' - prix exact\n" .
                           "• 'moins de 30000' - budget max\n" .
                           "• 'hôtel à Dakar' - par quartier\n" .
                           "• 'entre 20000 et 50000' - fourchette de prix"
            ];
        }
        
        // === RÉSULTATS ===
        if ($filteredHotels->isEmpty()) {
            return [
                'type' => 'text',
                'reply' => "😕 Désolé, aucun hôtel ne correspond à votre recherche.\n\n" .
                           "Propositions :\n" .
                           "• Essayez un budget différent\n" .
                           "• Essayez un autre quartier\n" .
                           "• Tapez 'hôtel moins cher' pour voir les meilleurs prix"
            ];
        }
        
        // Trier par prix si demandé
        if ($sortDirection === 'asc') {
            $filteredHotels = $filteredHotels->sortBy('price');
        } elseif ($sortDirection === 'desc') {
            $filteredHotels = $filteredHotels->sortByDesc('price');
        }
        
        return [
            'type' => 'hotels',
            'count' => $filteredHotels->count(),
            'message' => $messagePrefix ?? "🏨 Voici les hôtels trouvés :",
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