<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Hotel;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

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
        
        // Récupérer tous les hôtels de l'utilisateur
        $hotels = Hotel::where('user_id', $user->id)->get();

        // Vérifier si l'utilisateur a des hôtels
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "🏨 **Bienvenue !**\n\nVous n'avez pas encore d'hôtels dans votre catalogue.\n\n💡 Commencez par ajouter des hôtels depuis votre tableau de bord, puis revenez me poser vos questions !"
            ]);
        }

        // Filtrer les hôtels selon la demande
        $filteredHotels = $this->filterHotels($hotels, $message, $currency);

        // Si aucun hôtel trouvé après filtrage
        if ($filteredHotels->isEmpty()) {
            return $this->noResultsResponse($message, $hotels, $currency);
        }

        // Pour les demandes simples, retourner directement les hôtels
        if ($this->isSimpleRequest($message)) {
            return response()->json([
                'type' => 'hotels',
                'count' => $filteredHotels->count(),
                'message' => $this->getResultMessage($message, $filteredHotels->count(), $currency),
                'data' => $this->formatHotels($filteredHotels)
            ]);
        }

        // Appel à Grok pour une réponse naturelle (optionnel)
        $reply = $this->callGrokForExplanation($message, $filteredHotels, $currency);
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filteredHotels->count(),
            'message' => $reply,
            'data' => $this->formatHotels($filteredHotels)
        ]);
    }

    /**
     * Filtrage intelligent des hôtels
     */
    private function filterHotels(Collection $hotels, string $message, string $currency): Collection
    {
        $msg = strtolower($message);
        
        // === 1. HÔTEL LE MOINS CHER ===
        if ($this->contains($msg, ['moins cher', 'pas cher', 'économique', 'budget', 'le moins cher', 'meilleur prix'])) {
            return $hotels->sortBy('price')->take(3);
        }
        
        // === 2. HÔTEL LE PLUS CHER ===
        if ($this->contains($msg, ['plus cher', 'luxe', 'haut de gamme', 'le plus cher', 'premium'])) {
            return $hotels->sortByDesc('price')->take(3);
        }
        
        // === 3. PRIX EXACT (ex: "hôtel à 25000" ou "25000") ===
        $exactPrice = $this->extractExactPrice($msg);
        if ($exactPrice !== null) {
            $result = $hotels->where('price', $exactPrice);
            if ($result->isNotEmpty()) {
                return $result;
            }
            // Si prix exact non trouvé, on cherche les prix proches
            return $this->findClosestPrices($hotels, $exactPrice, 3);
        }
        
        // === 4. BUDGET MAX (ex: "moins de 30000") ===
        $maxBudget = $this->extractMaxBudget($msg);
        if ($maxBudget !== null) {
            return $hotels->where('price', '<=', $maxBudget)->sortBy('price');
        }
        
        // === 5. BUDGET MIN (ex: "plus de 50000") ===
        $minBudget = $this->extractMinBudget($msg);
        if ($minBudget !== null) {
            return $hotels->where('price', '>=', $minBudget)->sortBy('price');
        }
        
        // === 6. FOURCHETTE DE PRIX (ex: "entre 20000 et 50000") ===
        $priceRange = $this->extractPriceRange($msg);
        if ($priceRange !== null) {
            return $hotels->whereBetween('price', [$priceRange['min'], $priceRange['max']])->sortBy('price');
        }
        
        // === 7. RECHERCHE PAR ZONE/QUARTIER ===
        $zone = $this->extractZone($msg);
        if ($zone !== null) {
            return $hotels->filter(function ($hotel) use ($zone) {
                return str_contains(strtolower($hotel->address), strtolower($zone));
            })->sortBy('price');
        }
        
        // === 8. RECHERCHE PAR NOM D'HÔTEL ===
        $hotelName = $this->extractHotelName($msg);
        if ($hotelName !== null) {
            return $hotels->filter(function ($hotel) use ($hotelName) {
                return str_contains(strtolower($hotel->name), strtolower($hotelName));
            });
        }
        
        // === 9. DEMANDE "TOUS LES HÔTELS" ===
        if ($this->contains($msg, ['tous', 'liste', 'tous les hôtels', 'affiche tout', 'tous les hotels'])) {
            return $hotels->sortBy('price');
        }
        
        // === 10. PAR DÉFAUT : retourner tous les hôtels (ou suggestion) ===
        return $hotels->sortBy('price');
    }

    /**
     * Trouve les prix les plus proches d'une valeur cible
     */
    private function findClosestPrices(Collection $hotels, int $targetPrice, int $limit = 3): Collection
    {
        return $hotels->sortBy(function ($hotel) use ($targetPrice) {
            return abs($hotel->price - $targetPrice);
        })->take($limit);
    }

    /**
     * Extrait le prix exact d'un message
     */
    private function extractExactPrice(string $message): ?int
    {
        // Pattern pour "à 25000", "a 25000", "prix 25000"
        if (preg_match('/(?:[àa]|prix|à)\s*(\d+)/', $message, $matches)) {
            return (int)$matches[1];
        }
        // Pattern pour juste un chiffre
        if (preg_match('/^(\d+)$/', trim($message), $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Extrait le budget maximum
     */
    private function extractMaxBudget(string $message): ?int
    {
        if (preg_match('/(?:moins de|max|maximum|<)\s*(\d+)/', $message, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Extrait le budget minimum
     */
    private function extractMinBudget(string $message): ?int
    {
        if (preg_match('/(?:plus de|min|minimum|>)\s*(\d+)/', $message, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Extrait une fourchette de prix
     */
    private function extractPriceRange(string $message): ?array
    {
        if (preg_match('/entre\s*(\d+)\s*et\s*(\d+)/', $message, $matches)) {
            return ['min' => (int)$matches[1], 'max' => (int)$matches[2]];
        }
        return null;
    }

    /**
     * Extrait une zone géographique
     */
    private function extractZone(string $message): ?string
    {
        $zones = ['dakar', 'ngor', 'almadie', 'plateau', 'yoff', 'ouakam', 'mermoz', 'saly', 'mbour'];
        foreach ($zones as $zone) {
            if (str_contains($message, $zone)) {
                return $zone;
            }
        }
        return null;
    }

    /**
     * Extrait un nom d'hôtel
     */
    private function extractHotelName(string $message): ?string
    {
        $hotelNames = ['rade', 'terrou', 'radisson', 'king fahd', 'pullman', 'meridien'];
        foreach ($hotelNames as $name) {
            if (str_contains($message, $name)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Vérifie si le message contient un des mots
     */
    private function contains(string $message, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Détermine si la demande est simple (pas besoin d'IA)
     */
    private function isSimpleRequest(string $message): bool
    {
        $msg = strtolower($message);
        return $this->contains($msg, ['tous', 'liste', 'moins cher', 'plus cher', 'affiche']);
    }

    /**
     * Message de résultat selon la recherche
     */
    private function getResultMessage(string $message, int $count, string $currency): string
    {
        $msg = strtolower($message);
        
        if ($this->contains($msg, ['moins cher', 'pas cher', 'économique'])) {
            return "🏨 **Voici les {$count} hôtels les moins chers :**";
        }
        
        if ($this->contains($msg, ['plus cher', 'luxe'])) {
            return "🏨 **Voici les {$count} hôtels les plus chers :**";
        }
        
        if (preg_match('/(\d+)/', $msg, $matches) && $this->contains($msg, ['à', 'a'])) {
            $price = (int)$matches[1];
            return "🏨 **Hôtels à " . number_format($price, 0, ',', ' ') . " {$currency} :**";
        }
        
        if (preg_match('/entre\s*(\d+)\s*et\s*(\d+)/', $msg, $matches)) {
            $min = (int)$matches[1];
            $max = (int)$matches[2];
            return "🏨 **Hôtels entre " . number_format($min, 0, ',', ' ') . " et " . number_format($max, 0, ',', ' ') . " {$currency} :**";
        }
        
        $zone = $this->extractZone($msg);
        if ($zone) {
            return "📍 **Hôtels situés à " . ucfirst($zone) . " :**";
        }
        
        return "🏨 **Résultats de votre recherche ({$count}) :**";
    }

    /**
     * Réponse quand aucun résultat
     */
    private function noResultsResponse(string $message, Collection $allHotels, string $currency): JsonResponse
    {
        $msg = strtolower($message);
        $minPrice = $allHotels->min('price');
        $maxPrice = $allHotels->max('price');
        
        // Extraire le prix recherché
        preg_match('/(\d+)/', $msg, $matches);
        $searchedPrice = isset($matches[1]) ? (int)$matches[1] : null;
        
        $reply = "😕 **Aucun hôtel trouvé**\n\n";
        
        if ($searchedPrice) {
            // Trouver le prix le plus proche
            $closest = $this->findClosestPrices($allHotels, $searchedPrice, 1)->first();
            if ($closest) {
                $diff = abs($closest->price - $searchedPrice);
                $reply .= "💡 **Prix le plus proche :**\n";
                $reply .= "• {$closest->name} à " . number_format($closest->price, 0, ',', ' ') . " {$currency}\n";
                $reply .= "  (écart de " . number_format($diff, 0, ',', ' ') . " {$currency})\n\n";
            }
        }
        
        $reply .= "📝 **Suggestions :**\n";
        $reply .= "• Prix minimum : " . number_format($minPrice, 0, ',', ' ') . " {$currency}\n";
        $reply .= "• Prix maximum : " . number_format($maxPrice, 0, ',', ' ') . " {$currency}\n\n";
        $reply .= "🔍 **Essayez :**\n";
        $reply .= "• 'hôtel moins cher'\n";
        $reply .= "• 'tous les hôtels'\n";
        
        if ($searchedPrice && $searchedPrice < $minPrice) {
            $reply .= "• 'moins de " . number_format($minPrice + 5000, 0, ',', ' ') . "'\n";
        } elseif ($searchedPrice && $searchedPrice > $maxPrice) {
            $reply .= "• 'plus de " . number_format($maxPrice - 5000, 0, ',', ' ') . "'\n";
        }
        
        return response()->json([
            'type' => 'text',
            'reply' => $reply
        ]);
    }

    /**
     * Appel à Grok pour une explication naturelle
     */
    private function callGrokForExplanation(string $message, Collection $hotels, string $currency): string
    {
        $apiKey = env('XAI_API_KEY');
        
        if (!$apiKey || $hotels->isEmpty()) {
            return $this->getResultMessage($message, $hotels->count(), $currency);
        }
        
        try {
            $hotelText = $this->formatForAI($hotels);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post('https://api.x.ai/v1/chat/completions', [
                'model' => 'grok-beta',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Tu es un assistant hôtelier. Tu expliques les résultats de façon simple et humaine. Sois bref et utilise des émojis. Ne JAMAIS inventer d'hôtels."
                    ],
                    [
                        'role' => 'user',
                        'content' => "QUESTION: {$message}\nRÉSULTATS: {$hotelText}\nDonne une réponse courte et utile."
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 200,
            ]);
            
            if ($response->successful()) {
                $reply = $response->json()['choices'][0]['message']['content'] ?? "";
                if (!empty($reply)) {
                    return $reply;
                }
            }
        } catch (\Exception $e) {
            Log::error('Grok API error: ' . $e->getMessage());
        }
        
        return $this->getResultMessage($message, $hotels->count(), $currency);
    }

    /**
     * Format pour l'IA
     */
    private function formatForAI(Collection $hotels): string
    {
        if ($hotels->isEmpty()) {
            return "Aucun hôtel trouvé.";
        }
        
        $text = "";
        foreach ($hotels->take(5) as $h) {
            $text .= "- {$h->name}, " . number_format($h->price, 0, ',', ' ') . " {$h->currency}, {$h->address}\n";
        }
        
        if ($hotels->count() > 5) {
            $text .= "Et " . ($hotels->count() - 5) . " autres hôtels...";
        }
        
        return $text;
    }

    /**
     * Formatage des hôtels pour la réponse JSON
     */
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