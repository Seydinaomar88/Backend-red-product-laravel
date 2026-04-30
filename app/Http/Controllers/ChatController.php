<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Hotel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Point d'entrée principal du chat - Assistant IA Grok
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $message = trim($request->message);
        $userId = $request->user()->id;
        $userCurrency = $request->user()->currency ?? 'FCFA';
        $userLat = $request->latitude ? (float)$request->latitude : null;
        $userLng = $request->longitude ? (float)$request->longitude : null;

        // Récupérer les hôtels de l'utilisateur
        $hotels = Hotel::where('user_id', $userId)->get();
        
        // Statistiques
        $stats = [
            'total' => $hotels->count(),
            'min_price' => $hotels->min('price'),
            'max_price' => $hotels->max('price'),
            'avg_price' => round($hotels->avg('price')),
            'zones' => $this->getUniqueZones($hotels)
        ];

        // Appel à l'API Grok pour une réponse intelligente
        return $this->callGrokAPI($message, $hotels, $userCurrency, $stats, $userLat, $userLng);
    }

    /**
     * Appel à l'API Grok (xAI)
     */
    private function callGrokAPI(string $message, Collection $hotels, string $currency, array $stats, ?float $userLat, ?float $userLng): JsonResponse
    {
        $apiKey = env('XAI_API_KEY');
        
        // Si pas de clé API, utiliser la logique classique
        if (!$apiKey) {
            Log::warning('XAI_API_KEY manquante, utilisation du fallback');
            return $this->processMessage($message, $hotels, $currency, $stats, $userLat, $userLng);
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
                'phone' => $hotel->phone ?? 'Non renseigné',
                'email' => $hotel->email ?? 'Non renseigné',
            ];
        })->toArray();

        // Construction du prompt pour Grok
        $systemPrompt = "Tu es un assistant hôtelier professionnel et sympathique. Tu t'appelles 'Assistant Red Product'.

Voici la liste des hôtels disponibles (au format JSON) :
" . json_encode($hotelsData, JSON_PRETTY_PRINT) . "

Statistiques :
- Nombre total d'hôtels : {$stats['total']}
- Prix minimum : " . number_format($stats['min_price'], 0, ',', ' ') . " {$currency}
- Prix maximum : " . number_format($stats['max_price'], 0, ',', ' ') . " {$currency}
- Prix moyen : " . number_format($stats['avg_price'], 0, ',', ' ') . " {$currency}

RÈGLES IMPORTANTES À RESPECTER :

1. **SALUTATION** : Si l'utilisateur dit bonjour, réponds chaleureusement avec un résumé des hôtels disponibles.

2. **RECHERCHE PAR PRIX** :
   - 'moins cher' ou 'pas cher' → retourne les 3 hôtels avec les prix les plus bas
   - 'plus cher' ou 'luxe' → retourne les 3 hôtels avec les prix les plus élevés
   - 'à 25000' → prix exact
   - 'moins de 30000' → tous les hôtels à moins de 30000
   - 'entre 20000 et 50000' → fourchette de prix

3. **RECHERCHE PAR LIEU** : Si l'utilisateur donne un quartier (Dakar, Ngor, Saly...), filtre les hôtels dans cette zone.

4. **RECHERCHE PAR NOM** : Si l'utilisateur donne un nom d'hôtel, donne tous les détails.

5. **CONTACT** : Si l'utilisateur demande un contact, donne le téléphone et l'email.

6. **RÉPONSE JSON** : Tu dois retourner UNIQUEMENT du JSON valide avec cette structure :
   {\"type\":\"text\",\"reply\":\"ta réponse\"}
   ou
   {\"type\":\"hotels\",\"count\":2,\"message\":\"message\",\"data\":[{\"id\":1,\"name\":\"Hotel\"}]}

7. Sois naturel, utilise des emojis, aide vraiment l'utilisateur.";

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
                'temperature' => 0.7,
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
                    return $this->processMessage($message, $hotels, $currency, $stats, $userLat, $userLng);
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
                                'price' => number_format($hotel->price, 0, ',', ' '),
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
                return response()->json($result);
            }
            
            Log::error('Erreur Grok API', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return $this->processMessage($message, $hotels, $currency, $stats, $userLat, $userLng);
            
        } catch (\Exception $e) {
            Log::error('Exception Grok API: ' . $e->getMessage());
            return $this->processMessage($message, $hotels, $currency, $stats, $userLat, $userLng);
        }
    }

    /**
     * Traite le message de l'utilisateur (logique classique en fallback)
     */
    private function processMessage(string $message, Collection $hotels, string $currency, array $stats, ?float $userLat, ?float $userLng): JsonResponse
    {
        $messageLower = strtolower($message);
        
        // === 1. SALUTATIONS ===
        if ($this->isGreeting($messageLower)) {
            return $this->greetingResponse($stats, $currency);
        }
        
        // === 2. REMERCIEMENTS ===
        if ($this->isThankYou($messageLower)) {
            return $this->thankYouResponse();
        }
        
        // === 3. DEMANDE D'AIDE ===
        if ($this->isHelpRequest($messageLower)) {
            return $this->helpResponse();
        }
        
        // === 4. RECHERCHE PAR PRIX EXACT ===
        $exactPrice = $this->extractExactPrice($messageLower);
        if ($exactPrice !== null) {
            return $this->priceExactResponse($hotels, $exactPrice, $currency);
        }
        
        // === 5. RECHERCHE FOURCHETTE ===
        $priceRange = $this->extractPriceRange($messageLower);
        if ($priceRange !== null) {
            return $this->priceRangeResponse($hotels, $priceRange['min'], $priceRange['max'], $currency);
        }
        
        // === 6. BUDGET MAX ===
        $maxBudget = $this->extractMaxBudget($messageLower);
        if ($maxBudget !== null) {
            return $this->maxBudgetResponse($hotels, $maxBudget, $currency);
        }
        
        // === 7. BUDGET MIN ===
        $minBudget = $this->extractMinBudget($messageLower);
        if ($minBudget !== null) {
            return $this->minBudgetResponse($hotels, $minBudget, $currency);
        }
        
        // === 8. PRIX PROCHE ===
        $closestPrice = $this->extractClosestPrice($messageLower);
        if ($closestPrice !== null) {
            return $this->closestPriceResponse($hotels, $closestPrice, $currency);
        }
        
        // === 9. MOINS CHER ===
        if ($this->isCheapestRequest($messageLower)) {
            return $this->cheapestHotelsResponse($hotels, $currency);
        }
        
        // === 10. PLUS CHER ===
        if ($this->isExpensiveRequest($messageLower)) {
            return $this->expensiveHotelsResponse($hotels, $currency);
        }
        
        // === 11. PAR ZONE ===
        $zone = $this->extractZone($messageLower);
        if ($zone !== null) {
            return $this->zoneHotelsResponse($hotels, $zone, $currency);
        }
        
        // === 12. CONTACT D'UN HÔTEL ===
        if ($this->isContactRequest($messageLower)) {
            return $this->contactDetailsResponse($hotels, $messageLower, $currency);
        }
        
        // === 13. PAR NOM ===
        $hotelName = $this->extractHotelName($messageLower);
        if ($hotelName !== null) {
            return $this->hotelByNameResponse($hotels, $hotelName, $currency);
        }
        
        // === 14. GÉOLOCALISATION ===
        if ($userLat !== null && $userLng !== null && $this->isNearbyRequest($messageLower)) {
            return $this->nearbyHotelsResponse($hotels, $userLat, $userLng, $currency);
        }
        
        // === 15. TOUS LES HÔTELS ===
        if ($this->isListAllRequest($messageLower)) {
            return $this->allHotelsResponse($hotels, $currency);
        }
        
        // === 16. RÉPONSE PAR DÉFAUT ===
        return $this->smartHelpResponse($messageLower);
    }

    // ========== MÉTHODES DE DÉTECTION ==========

    private function isGreeting(string $message): bool
    {
        $greetings = ['bonjour', 'salut', 'coucou', 'hello', 'hi', 'hey', 'bonsoir'];
        foreach ($greetings as $greeting) {
            if (str_contains($message, $greeting)) {
                return true;
            }
        }
        return false;
    }

    private function isThankYou(string $message): bool
    {
        $thanks = ['merci', 'thanks', 'thank you', 'super', 'génial', 'parfait'];
        foreach ($thanks as $thank) {
            if (str_contains($message, $thank)) {
                return true;
            }
        }
        return false;
    }

    private function isHelpRequest(string $message): bool
    {
        $helps = ['aide', 'help', 'que peux-tu faire', 'comment ça marche'];
        foreach ($helps as $help) {
            if (str_contains($message, $help)) {
                return true;
            }
        }
        return false;
    }

    private function isCheapestRequest(string $message): bool
    {
        $cheapest = ['moins cher', 'pas cher', 'économique', 'budget', 'le moins cher', 'meilleur prix'];
        foreach ($cheapest as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isExpensiveRequest(string $message): bool
    {
        $expensive = ['plus cher', 'luxe', 'haut de gamme', 'premium', 'le plus cher'];
        foreach ($expensive as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isListAllRequest(string $message): bool
    {
        $listAll = ['tous les hôtels', 'liste des hôtels', 'affiche tout', 'tous mes hôtels'];
        foreach ($listAll as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isNearbyRequest(string $message): bool
    {
        $nearby = ['proche', 'près de', 'à côté', 'autour de', 'distance'];
        foreach ($nearby as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isContactRequest(string $message): bool
    {
        $contactWords = ['contact', 'téléphone', 'tel', 'phone', 'email', 'coordonnées'];
        foreach ($contactWords as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    // ========== MÉTHODES D'EXTRACTION ==========

    private function extractExactPrice(string $message): ?int
    {
        preg_match('/[àa]\s*(\d+)/', $message, $matches);
        if (isset($matches[1])) {
            return (int)$matches[1];
        }
        return null;
    }

    private function extractPriceRange(string $message): ?array
    {
        preg_match('/entre\s*(\d+)\s*et\s*(\d+)/', $message, $matches);
        if (count($matches) >= 3) {
            return ['min' => (int)$matches[1], 'max' => (int)$matches[2]];
        }
        return null;
    }

    private function extractMaxBudget(string $message): ?int
    {
        if (str_contains($message, 'moins de')) {
            preg_match('/(\d+)/', $message, $matches);
            return isset($matches[1]) ? (int)$matches[1] : null;
        }
        return null;
    }

    private function extractMinBudget(string $message): ?int
    {
        if (str_contains($message, 'plus de')) {
            preg_match('/(\d+)/', $message, $matches);
            return isset($matches[1]) ? (int)$matches[1] : null;
        }
        return null;
    }

    private function extractClosestPrice(string $message): ?int
    {
        if (str_contains($message, 'proche')) {
            preg_match('/(\d+)/', $message, $matches);
            return isset($matches[1]) ? (int)$matches[1] : null;
        }
        return null;
    }

    private function extractZone(string $message): ?string
    {
        $zones = ['dakar', 'ngor', 'almadie', 'plateau', 'yoff', 'saly', 'mbour'];
        foreach ($zones as $zone) {
            if (str_contains($message, $zone)) {
                return ucfirst($zone);
            }
        }
        return null;
    }

    private function extractHotelName(string $message): ?string
    {
        $hotelNames = ['rade', 'terrou', 'radisson', 'king fahd', 'pullman'];
        foreach ($hotelNames as $name) {
            if (str_contains($message, $name)) {
                return $name;
            }
        }
        return null;
    }

    private function getUniqueZones(Collection $hotels): array
    {
        $zones = [];
        $zoneList = ['Dakar', 'Ngor', 'Almadies', 'Plateau', 'Yoff', 'Saly', 'Mbour'];
        foreach ($hotels as $hotel) {
            foreach ($zoneList as $zone) {
                if (str_contains($hotel->address, $zone) && !in_array($zone, $zones, true)) {
                    $zones[] = $zone;
                    break;
                }
            }
        }
        return $zones;
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return round($earthRadius * $c, 2);
    }

    // ========== RÉPONSES ==========

    private function greetingResponse(array $stats, string $currency): JsonResponse
    {
        if ($stats['total'] === 0) {
            return response()->json([
                'type' => 'text',
                'reply' => "👋 Bonjour ! Je suis votre assistant hôtelier.\n\n📊 Vous n'avez pas encore d'hôtels.\n\n💡 Ajoutez des hôtels depuis votre tableau de bord !"
            ]);
        }
        
        $reply = "👋 **Bonjour !**\n\n";
        $reply .= "📊 J'ai **{$stats['total']} hôtels** dans votre catalogue\n";
        $reply .= "💰 **Prix** : de " . number_format($stats['min_price'], 0, ',', ' ') . " à " . number_format($stats['max_price'], 0, ',', ' ') . " {$currency}\n";
        $reply .= "📈 **Prix moyen** : " . number_format($stats['avg_price'], 0, ',', ' ') . " {$currency}\n\n";
        
        if (!empty($stats['zones'])) {
            $reply .= "📍 **Zones disponibles** : " . implode(', ', $stats['zones']) . "\n\n";
        }
        
        $reply .= "🔍 **Que recherchez-vous ?**\n";
        $reply .= "• 'hôtel moins cher'\n• 'hôtel à 25000'\n• 'hôtel à Dakar'\n• 'contact Terrou-Bi'\n• 'tous les hôtels'\n\n";
        $reply .= "💡 Tapez **aide** pour plus d'exemples";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    private function thankYouResponse(): JsonResponse
    {
        return response()->json([
            'type' => 'text',
            'reply' => "Avec plaisir ! 😊 N'hésitez pas si vous avez d'autres questions."
        ]);
    }

    private function helpResponse(): JsonResponse
    {
        $reply = "📚 **Guide d'utilisation** 📚\n\n";
        $reply .= "💰 **Prix** :\n• 'hôtel moins cher'\n• 'hôtel à 25000'\n• 'moins de 30000'\n• 'entre 20000 et 50000'\n\n";
        $reply .= "📍 **Lieu** :\n• 'hôtel à Dakar'\n• 'hôtel à Ngor'\n\n";
        $reply .= "📞 **Contact** :\n• 'contact Terrou-Bi'\n\n";
        $reply .= "📋 **Autres** :\n• 'tous les hôtels'\n• 'prix'\n• 'quartiers'\n\n";
        $reply .= "💬 Posez votre question naturellement !";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    private function smartHelpResponse(string $message): JsonResponse
    {
        return response()->json([
            'type' => 'text',
            'reply' => "🤔 Je n'ai pas compris votre demande.\n\n📝 Voici ce que je peux faire :\n• Recherche par prix\n• Recherche par quartier\n• Donner les coordonnées d'un hôtel\n• Lister tous les hôtels\n\n💡 Tapez **aide** pour voir tous les exemples"
        ]);
    }

    private function priceExactResponse(Collection $hotels, int $price, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', $price);
        
        if ($filtered->isEmpty()) {
            $closestHigher = $hotels->where('price', '>', $price)->sortBy('price')->first();
            $closestLower = $hotels->where('price', '<', $price)->sortByDesc('price')->first();
            
            $suggestions = "";
            if ($closestLower) {
                $suggestions .= "• Moins cher : " . number_format($closestLower->price, 0, ',', ' ') . " {$currency} - {$closestLower->name}\n";
            }
            if ($closestHigher) {
                $suggestions .= "• Plus cher : " . number_format($closestHigher->price, 0, ',', ' ') . " {$currency} - {$closestHigher->name}\n";
            }
            
            $reply = "😕 Aucun hôtel trouvé à " . number_format($price, 0, ',', ' ') . " {$currency}\n\n";
            if ($suggestions) {
                $reply .= "💡 Prix les plus proches :\n" . $suggestions;
            }
            
            return response()->json(['type' => 'text', 'reply' => $reply]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtel à " . number_format($price, 0, ',', ' ') . " {$currency} :",
            'data' => $this->formatHotels($filtered)
        ]);
    }

    private function priceRangeResponse(Collection $hotels, int $min, int $max, string $currency): JsonResponse
    {
        $filtered = $hotels->whereBetween('price', [$min, $max]);
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel trouvé entre " . number_format($min, 0, ',', ' ') . " et " . number_format($max, 0, ',', ' ') . " {$currency}"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtels entre " . number_format($min, 0, ',', ' ') . " et " . number_format($max, 0, ',', ' ') . " {$currency} :",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    private function maxBudgetResponse(Collection $hotels, int $max, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', '<=', $max);
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel trouvé à moins de " . number_format($max, 0, ',', ' ') . " {$currency}"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtels à moins de " . number_format($max, 0, ',', ' ') . " {$currency} :",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    private function minBudgetResponse(Collection $hotels, int $min, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', '>=', $min);
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel trouvé à plus de " . number_format($min, 0, ',', ' ') . " {$currency}"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtels à plus de " . number_format($min, 0, ',', ' ') . " {$currency} :",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    private function closestPriceResponse(Collection $hotels, int $targetPrice, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "Aucun hôtel disponible."]);
        }
        
        $closest = $hotels->sortBy(function ($hotel) use ($targetPrice) {
            return abs($hotel->price - $targetPrice);
        })->first();
        
        $difference = abs($closest->price - $targetPrice);
        $direction = $closest->price > $targetPrice ? "plus cher" : "moins cher";
        
        $reply = "🎯 Prix le plus proche de " . number_format($targetPrice, 0, ',', ' ') . " {$currency}\n\n";
        $reply .= "🏨 {$closest->name}\n";
        $reply .= "💰 Prix : " . number_format($closest->price, 0, ',', ' ') . " {$currency}\n";
        $reply .= "📍 Adresse : {$closest->address}\n";
        $reply .= "📞 Tél : " . ($closest->phone ?? 'Non renseigné') . "\n";
        $reply .= "📊 Écart : " . number_format($difference, 0, ',', ' ') . " {$currency} ({$direction})";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    private function cheapestHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "Aucun hôtel disponible."]);
        }
        
        $cheapest = $hotels->sortBy('price')->take(3);
        
        return response()->json([
            'type' => 'hotels',
            'count' => $cheapest->count(),
            'message' => "🏨 Voici les hôtels les moins chers :",
            'data' => $this->formatHotels($cheapest)
        ]);
    }

    private function expensiveHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "Aucun hôtel disponible."]);
        }
        
        $expensive = $hotels->sortByDesc('price')->take(3);
        
        return response()->json([
            'type' => 'hotels',
            'count' => $expensive->count(),
            'message' => "🏨 Voici les hôtels les plus chers :",
            'data' => $this->formatHotels($expensive)
        ]);
    }

    private function zoneHotelsResponse(Collection $hotels, string $zone, string $currency): JsonResponse
    {
        $filtered = $hotels->filter(function ($hotel) use ($zone) {
            return str_contains($hotel->address, $zone);
        });
        
        if ($filtered->isEmpty()) {
            $zones = $this->getUniqueZones($hotels);
            $zoneList = !empty($zones) ? implode(', ', $zones) : 'aucun';
            return response()->json([
                'type' => 'text',
                'reply' => "📍 Aucun hôtel trouvé à {$zone}\n\n💡 Quartiers disponibles : " . $zoneList
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "📍 Hôtels situés à {$zone} :",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    private function contactDetailsResponse(Collection $hotels, string $message, string $currency): JsonResponse
    {
        // Chercher l'hôtel par nom dans la phrase
        $hotelNames = ['rade', 'terrou', 'radisson', 'king fahd', 'pullman'];
        $foundHotel = null;
        
        foreach ($hotelNames as $name) {
            if (str_contains($message, $name)) {
                $foundHotel = $hotels->first(function ($hotel) use ($name) {
                    return str_contains(strtolower($hotel->name), $name);
                });
                break;
            }
        }
        
        if ($foundHotel) {
            $reply = "📞 **Coordonnées de {$foundHotel->name}**\n\n";
            $reply .= "🏨 **Nom** : {$foundHotel->name}\n";
            $reply .= "📍 **Adresse** : {$foundHotel->address}\n";
            $reply .= "📞 **Téléphone** : " . ($foundHotel->phone ?? 'Non renseigné') . "\n";
            $reply .= "📧 **Email** : " . ($foundHotel->email ?? 'Non renseigné') . "\n";
            $reply .= "💰 **Prix** : " . number_format($foundHotel->price, 0, ',', ' ') . " {$foundHotel->currency}";
            
            return response()->json(['type' => 'text', 'reply' => $reply]);
        }
        
        return response()->json([
            'type' => 'text',
            'reply' => "📞 Donnez-moi le nom exact de l'hôtel (ex: 'contact Terrou-Bi')"
        ]);
    }

    private function hotelByNameResponse(Collection $hotels, string $name, string $currency): JsonResponse
    {
        $filtered = $hotels->filter(function ($hotel) use ($name) {
            return str_contains(strtolower($hotel->name), $name);
        });
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel nommé '{$name}' trouvé."
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtel trouvé :",
            'data' => $this->formatHotels($filtered)
        ]);
    }

    private function nearbyHotelsResponse(Collection $hotels, float $lat, float $lng, string $currency): JsonResponse
    {
        $hotelsWithDistance = $hotels->map(function ($hotel) use ($lat, $lng) {
            if ($hotel->latitude && $hotel->longitude) {
                $hotel->distance = $this->calculateDistance($lat, $lng, (float)$hotel->latitude, (float)$hotel->longitude);
            } else {
                $hotel->distance = null;
            }
            return $hotel;
        })->filter(function ($hotel) {
            return $hotel->distance !== null;
        })->sortBy('distance')->take(5);
        
        if ($hotelsWithDistance->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "🗺️ Géolocalisation non disponible\n\nAjoutez les coordonnées GPS à vos hôtels."
            ]);
        }
        
        $reply = "🗺️ **Hôtels les plus proches :**\n\n";
        foreach ($hotelsWithDistance as $hotel) {
            $reply .= "🏨 **{$hotel->name}**\n";
            $reply .= "📍 Distance : {$hotel->distance} km\n";
            $reply .= "💰 Prix : " . number_format($hotel->price, 0, ',', ' ') . " {$currency}\n\n";
        }
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    private function allHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📊 Aucun hôtel disponible.\n\nCommencez par en ajouter depuis votre tableau de bord !"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $hotels->count(),
            'message' => "🏨 Liste de tous vos hôtels (" . $hotels->count() . ") :",
            'data' => $this->formatHotels($hotels->sortBy('price'))
        ]);
    }

    private function formatHotels(Collection $hotels): array
    {
        return $hotels->map(function ($hotel) {
            return [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'address' => $hotel->address,
                'price' => number_format($hotel->price, 0, ',', ' '),
                'currency' => $hotel->currency,
                'image' => $hotel->image,
                'phone' => $hotel->phone ?? 'Non renseigné',
                'email' => $hotel->email ?? 'Non renseigné',
            ];
        })->values()->toArray();
    }
}