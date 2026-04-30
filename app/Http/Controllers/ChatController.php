<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Hotel;

class ChatController extends Controller
{
    /**
     * Point d'entrée principal du chat - Assistant hôtelier complet
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $message = strtolower(trim($request->message));
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

        return $this->processMessage($message, $hotels, $userCurrency, $stats, $userLat, $userLng);
    }

    /**
     * Traite le message de l'utilisateur
     */
    private function processMessage(string $message, Collection $hotels, string $currency, array $stats, ?float $userLat, ?float $userLng): JsonResponse
    {
        // === 1. SALUTATIONS ===
        if ($this->isGreeting($message)) {
            return $this->greetingResponse($stats, $currency);
        }
        
        // === 2. REMERCIEMENTS ===
        if ($this->isThankYou($message)) {
            return $this->thankYouResponse();
        }
        
        // === 3. DEMANDE D'AIDE ===
        if ($this->isHelpRequest($message)) {
            return $this->helpResponse();
        }
        
        // === 4. RECHERCHE PAR PRIX EXACT ===
        $exactPrice = $this->extractExactPrice($message);
        if ($exactPrice !== null) {
            return $this->priceExactResponse($hotels, $exactPrice, $currency);
        }
        
        // === 5. RECHERCHE FOURCHETTE DE PRIX ===
        $priceRange = $this->extractPriceRange($message);
        if ($priceRange !== null) {
            return $this->priceRangeResponse($hotels, $priceRange['min'], $priceRange['max'], $currency);
        }
        
        // === 6. RECHERCHE PAR BUDGET MAX ===
        $maxBudget = $this->extractMaxBudget($message);
        if ($maxBudget !== null) {
            return $this->maxBudgetResponse($hotels, $maxBudget, $currency);
        }
        
        // === 7. RECHERCHE PAR BUDGET MIN ===
        $minBudget = $this->extractMinBudget($message);
        if ($minBudget !== null) {
            return $this->minBudgetResponse($hotels, $minBudget, $currency);
        }
        
        // === 8. PRIX LE PLUS PROCHE ===
        $closestPrice = $this->extractClosestPrice($message);
        if ($closestPrice !== null) {
            return $this->closestPriceResponse($hotels, $closestPrice, $currency);
        }
        
        // === 9. HÔTEL LE MOINS CHER ===
        if ($this->isCheapestRequest($message)) {
            return $this->cheapestHotelsResponse($hotels, $currency);
        }
        
        // === 10. HÔTEL LE PLUS CHER ===
        if ($this->isExpensiveRequest($message)) {
            return $this->expensiveHotelsResponse($hotels, $currency);
        }
        
        // === 11. RECHERCHE PAR ZONE/QUARTIER ===
        $zone = $this->extractZone($message);
        if ($zone !== null) {
            return $this->zoneHotelsResponse($hotels, $zone, $currency);
        }
        
        // === 12. RECHERCHE PAR ADRESSE ===
        $address = $this->extractAddress($message);
        if ($address !== null) {
            return $this->addressHotelsResponse($hotels, $address, $currency);
        }
        
        // === 13. RECHERCHE PAR CONTACT (TÉLÉPHONE/EMAIL) ===
        $contact = $this->extractContact($message);
        if ($contact !== null) {
            return $this->contactHotelsResponse($hotels, $contact, $currency);
        }
        
        // === 14. RECHERCHE PAR NOM D'HÔTEL ===
        $hotelName = $this->extractHotelName($message);
        if ($hotelName !== null) {
            return $this->hotelByNameResponse($hotels, $hotelName, $currency);
        }
        
        // === 15. QUESTION SUR LES PRIX ===
        if ($this->isPriceQuestion($message)) {
            return $this->priceInfoResponse($hotels, $currency);
        }
        
        // === 16. QUESTION SUR LES ZONES ===
        if ($this->isZoneQuestion($message)) {
            return $this->zoneInfoResponse($hotels);
        }
        
        // === 17. GÉOLOCALISATION (HÔTELS PROCHES) ===
        if ($userLat !== null && $userLng !== null && ($this->isNearbyRequest($message) || str_contains($message, 'proche'))) {
            return $this->nearbyHotelsResponse($hotels, $userLat, $userLng, $currency);
        }
        
        // === 18. AFFICHER TOUS LES HÔTELS ===
        if ($this->isListAllRequest($message) || $message === 'hotel' || $message === 'hôtels') {
            return $this->allHotelsResponse($hotels, $currency);
        }
        
        // === 19. RÉPONSE PAR DÉFAUT ===
        return $this->smartHelpResponse($message);
    }

    // ========== MÉTHODES DE DÉTECTION ==========

    private function isGreeting(string $message): bool
    {
        $greetings = ['bonjour', 'salut', 'coucou', 'hello', 'hi', 'hey', 'bonsoir', 'yo'];
        foreach ($greetings as $greeting) {
            if (str_contains($message, $greeting)) {
                return true;
            }
        }
        return false;
    }

    private function isThankYou(string $message): bool
    {
        $thanks = ['merci', 'thanks', 'thank you', 'super', 'génial', 'parfait', 'top'];
        foreach ($thanks as $thank) {
            if (str_contains($message, $thank)) {
                return true;
            }
        }
        return false;
    }

    private function isHelpRequest(string $message): bool
    {
        $helps = ['aide', 'help', 'que peux-tu faire', 'comment ça marche', 'aide moi'];
        foreach ($helps as $help) {
            if (str_contains($message, $help)) {
                return true;
            }
        }
        return false;
    }

    private function isPriceQuestion(string $message): bool
    {
        $priceQuestions = ['prix', 'tarif', 'combien', 'coût', 'budget moyen'];
        foreach ($priceQuestions as $pq) {
            if (str_contains($message, $pq)) {
                return true;
            }
        }
        return false;
    }

    private function isZoneQuestion(string $message): bool
    {
        $zoneQuestions = ['quartier', 'zone', 'ville', 'secteur', 'où se trouvent', 'localisation'];
        foreach ($zoneQuestions as $zq) {
            if (str_contains($message, $zq)) {
                return true;
            }
        }
        return false;
    }

    private function isCheapestRequest(string $message): bool
    {
        $cheapest = ['moins cher', 'pas cher', 'économique', 'budget', 'le moins cher', 'meilleur prix', 'petit prix', 'le moins élevé'];
        foreach ($cheapest as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isExpensiveRequest(string $message): bool
    {
        $expensive = ['plus cher', 'luxe', 'haut de gamme', 'premium', 'le plus cher', 'le plus élevé'];
        foreach ($expensive as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isListAllRequest(string $message): bool
    {
        $listAll = ['tous les hôtels', 'liste des hôtels', 'affiche tout', 'tous mes hôtels', 'tous les hotels'];
        foreach ($listAll as $word) {
            if (str_contains($message, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isNearbyRequest(string $message): bool
    {
        $nearby = ['proche', 'près de', 'à côté', 'autour de', 'distance', 'géolocalisation'];
        foreach ($nearby as $word) {
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
        
        preg_match('/prix\s*(\d+)/', $message, $matches);
        if (isset($matches[1])) {
            return (int)$matches[1];
        }
        
        preg_match('/^(\d+)$/', trim($message), $matches);
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
        if (str_contains($message, 'moins de') || str_contains($message, 'max') || str_contains($message, 'maximum')) {
            preg_match('/(\d+)/', $message, $matches);
            return isset($matches[1]) ? (int)$matches[1] : null;
        }
        return null;
    }

    private function extractMinBudget(string $message): ?int
    {
        if (str_contains($message, 'plus de') || str_contains($message, 'min') || str_contains($message, 'minimum')) {
            preg_match('/(\d+)/', $message, $matches);
            return isset($matches[1]) ? (int)$matches[1] : null;
        }
        return null;
    }

    private function extractClosestPrice(string $message): ?int
    {
        if (str_contains($message, 'proche') || str_contains($message, 'approchant') || str_contains($message, 'environ')) {
            preg_match('/(\d+)/', $message, $matches);
            return isset($matches[1]) ? (int)$matches[1] : null;
        }
        return null;
    }

    private function extractZone(string $message): ?string
    {
        $zones = ['dakar', 'ngor', 'almadie', 'plateau', 'yoff', 'ouakam', 'mermoz', 'sicap', 'saly', 'mbour', 'lac rose'];
        foreach ($zones as $zone) {
            if (str_contains($message, $zone)) {
                return ucfirst($zone);
            }
        }
        return null;
    }

    private function extractAddress(string $message): ?string
    {
        if (str_contains($message, 'adresse') || str_contains($message, 'rue') || str_contains($message, 'boulevard')) {
            $address = preg_replace('/(adresse|rue|boulevard|à)\s*/', '', $message);
            return trim($address);
        }
        return null;
    }

    private function extractContact(string $message): ?string
    {
        // Extraire email
        preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $message, $emailMatch);
        if (!empty($emailMatch)) {
            return $emailMatch[0];
        }
        
        // Extraire téléphone (77, 78, 76, 70 + 8 chiffres)
        preg_match('/(77|78|76|70|75)[0-9]{7,8}/', $message, $phoneMatch);
        if (!empty($phoneMatch)) {
            return $phoneMatch[0];
        }
        
        if (str_contains($message, 'téléphone') || str_contains($message, 'contact') || str_contains($message, 'appeler')) {
            return 'contact';
        }
        
        return null;
    }

    private function extractHotelName(string $message): ?string
    {
        $hotelNames = ['rade', 'terrou-bi', 'terrou', 'radisson', 'king fahd', 'pullman', 'meridien', 'sheraton'];
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
        $zoneList = ['Dakar', 'Ngor', 'Almadies', 'Plateau', 'Yoff', 'Ouakam', 'Mermoz', 'Saly', 'Mbour'];
        
        foreach ($hotels as $hotel) {
            foreach ($zoneList as $zone) {
                if (str_contains($hotel->address, $zone)) {
                    if (!in_array($zone, $zones, true)) {
                        $zones[] = $zone;
                    }
                    break;
                }
            }
        }
        return $zones;
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return round($earthRadius * $c, 2);
    }

    // ========== RÉPONSES (RACCOURCIES POUR CONCISÉITÉ) ==========

    private function greetingResponse(array $stats, string $currency): JsonResponse
    {
        if ($stats['total'] === 0) {
            return response()->json([
                'type' => 'text',
                'reply' => "👋 **Bonjour !**\n\nJe suis votre assistant hôtelier.\n\n📊 Vous n'avez pas encore d'hôtels.\n\n💡 Ajoutez des hôtels depuis votre tableau de bord, puis revenez me poser vos questions !"
            ]);
        }
        
        $reply = "👋 **Bonjour !**\n\n";
        $reply .= "📊 J'ai **{$stats['total']} hôtels** dans votre catalogue\n";
        $reply .= "💰 **Prix** : de " . number_format($stats['min_price'], 0, ',', ' ') . " à " . number_format($stats['max_price'], 0, ',', ' ') . " {$currency}\n";
        $reply .= "📈 **Prix moyen** : " . number_format($stats['avg_price'], 0, ',', ' ') . " {$currency}\n\n";
        
        if (!empty($stats['zones'])) {
            $reply .= "📍 **Zones disponibles** : " . implode(', ', $stats['zones']) . "\n\n";
        }
        
        $reply .= "🔍 **Que puis-je faire pour vous ?**\n";
        $reply .= "• 💰 '**hôtel moins cher**' - meilleurs prix\n";
        $reply .= "• 💰 '**hôtel à 25000**' - prix exact\n";
        $reply .= "• 💰 '**moins de 30000**' - budget max\n";
        $reply .= "• 💰 '**entre 20000 et 50000**' - fourchette\n";
        $reply .= "• 📍 '**hôtel à Dakar**' - par quartier\n";
        $reply .= "• 📞 '**contact hôtel**' - téléphone/email\n";
        $reply .= "• 📋 '**tous les hôtels**' - liste complète\n\n";
        $reply .= "💡 **Tapez 'aide' pour plus d'exemples**";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    private function thankYouResponse(): JsonResponse
    {
        $replies = [
            "Avec plaisir ! 😊 Je reste à votre disposition.",
            "Je vous en prie ! 🎉 N'hésitez pas si vous avez d'autres questions.",
            "Service ! ✨ Besoin d'autre chose ?"
        ];
        return response()->json(['type' => 'text', 'reply' => $replies[array_rand($replies)]]);
    }

    private function helpResponse(): JsonResponse
    {
        $reply = "📚 **Guide d'utilisation complet** 📚\n\n";
        $reply .= "💰 **RECHERCHE PAR PRIX :**\n";
        $reply .= "• '**hôtel moins cher**' → 3 meilleurs prix\n";
        $reply .= "• '**hôtel à 25000**' → prix exact\n";
        $reply .= "• '**moins de 30000**' → budget maximum\n";
        $reply .= "• '**plus de 50000**' → budget minimum\n";
        $reply .= "• '**entre 20000 et 50000**' → fourchette\n";
        $reply .= "• '**prix proche de 25000**' → prix le plus approchant\n\n";
        
        $reply .= "📍 **RECHERCHE PAR LIEU :**\n";
        $reply .= "• '**hôtel à Dakar**' → par quartier\n";
        $reply .= "• '**adresse boulevard...**' → par adresse\n\n";
        
        $reply .= "📞 **RECHERCHE PAR CONTACT :**\n";
        $reply .= "• '**téléphone 771234567**' → par numéro\n";
        $reply .= "• '**email hotel@exemple.com**' → par email\n\n";
        
        $reply .= "🏨 **RECHERCHE PAR NOM :**\n";
        $reply .= "• '**hôtel Terrou**' → par nom\n\n";
        
        $reply .= "🗺️ **GÉOLOCALISATION :**\n";
        $reply .= "• '**hôtels proches de moi**' → avec votre position\n\n";
        
        $reply .= "📋 **AUTRES :**\n";
        $reply .= "• '**tous les hôtels**' → liste complète\n";
        $reply .= "• '**prix**' → infos sur les prix\n";
        $reply .= "• '**quartiers**' → zones disponibles\n\n";
        
        $reply .= "💬 **Posez votre question naturellement, je comprends !**";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    private function smartHelpResponse(string $message): JsonResponse
    {
        if (str_contains($message, 'hotel') || str_contains($message, 'hôtel')) {
            return response()->json([
                'type' => 'text',
                'reply' => "🏨 **Je peux vous aider à trouver des hôtels !**\n\n" .
                           "📝 **Exemples de recherche :**\n" .
                           "• 'hôtel moins cher' - les meilleurs prix\n" .
                           "• 'hôtel à 25000' - prix exact\n" .
                           "• 'moins de 30000' - budget max\n" .
                           "• 'hôtel à Dakar' - par quartier\n" .
                           "• 'tous les hôtels' - liste complète\n\n" .
                           "💡 Tapez **'aide'** pour plus d'exemples"
            ]);
        }
        
        return response()->json([
            'type' => 'text',
            'reply' => "🤔 **Je n'ai pas compris votre demande.**\n\n" .
                       "📝 **Voici ce que je peux faire :**\n" .
                       "• 💰 Recherche par prix (exact, fourchette, moins cher)\n" .
                       "• 📍 Recherche par quartier/adresse\n" .
                       "• 📞 Recherche par contact (téléphone/email)\n" .
                       "• 🏨 Recherche par nom d'hôtel\n" .
                       "• 🗺️ Hôtels proches de vous\n\n" .
                       "💡 Tapez **'aide'** pour voir tous les exemples"
        ]);
    }

    /**
     * PRIX EXACT - Avec suggestion si non trouvé
     */
    private function priceExactResponse(Collection $hotels, int $price, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', $price);
        
        if ($filtered->isEmpty()) {
            // Trouver les prix les plus proches
            $closestHigher = $hotels->where('price', '>', $price)->sortBy('price')->first();
            $closestLower = $hotels->where('price', '<', $price)->sortByDesc('price')->first();
            
            $suggestions = "";
            if ($closestLower) {
                $suggestions .= "• Moins cher : " . number_format($closestLower->price, 0, ',', ' ') . " {$currency} - {$closestLower->name}\n";
            }
            if ($closestHigher) {
                $suggestions .= "• Plus cher : " . number_format($closestHigher->price, 0, ',', ' ') . " {$currency} - {$closestHigher->name}\n";
            }
            
            $reply = "😕 **Aucun hôtel trouvé à " . number_format($price, 0, ',', ' ') . " {$currency}**\n\n";
            if ($suggestions) {
                $reply .= "💡 **Prix les plus proches :**\n" . $suggestions . "\n";
            }
            $reply .= "📝 **Suggestions :**\n";
            $reply .= "• 'moins de " . number_format($price, 0, ',', ' ') . "' - budget inférieur\n";
            $reply .= "• 'plus de " . number_format($price, 0, ',', ' ') . "' - budget supérieur\n";
            $reply .= "• 'hôtel moins cher' - meilleurs prix";
            
            return response()->json(['type' => 'text', 'reply' => $reply]);
        }
        
        $message = $filtered->count() === 1 
            ? "🏨 **Hôtel trouvé à " . number_format($price, 0, ',', ' ') . " {$currency} :**" 
            : "🏨 **" . $filtered->count() . " hôtels trouvés à " . number_format($price, 0, ',', ' ') . " {$currency} :**";
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => $message,
            'data' => $this->formatHotels($filtered)
        ]);
    }

    /**
     * PRIX LE PLUS PROCHE
     */
    private function closestPriceResponse(Collection $hotels, int $targetPrice, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "📊 Aucun hôtel disponible."]);
        }
        
        // Trouver l'hôtel avec le prix le plus proche
        $closest = $hotels->sortBy(function ($hotel) use ($targetPrice) {
            return abs($hotel->price - $targetPrice);
        })->first();
        
        $difference = abs($closest->price - $targetPrice);
        $direction = $closest->price > $targetPrice ? "plus cher" : "moins cher";
        
        $reply = "🎯 **Prix le plus proche de " . number_format($targetPrice, 0, ',', ' ') . " {$currency}**\n\n";
        $reply .= "🏨 **{$closest->name}**\n";
        $reply .= "💰 Prix : " . number_format($closest->price, 0, ',', ' ') . " {$currency}\n";
        $reply .= "📊 Écart : " . number_format($difference, 0, ',', ' ') . " {$currency} ({$direction})\n";
        $reply .= "📍 Adresse : {$closest->address}\n";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * FOURCHETTE DE PRIX
     */
    private function priceRangeResponse(Collection $hotels, int $min, int $max, string $currency): JsonResponse
    {
        $filtered = $hotels->whereBetween('price', [$min, $max]);
        
        if ($filtered->isEmpty()) {
            $reply = "😕 **Aucun hôtel trouvé entre " . number_format($min, 0, ',', ' ') . " et " . number_format($max, 0, ',', ' ') . " {$currency}**\n\n";
            $reply .= "💡 **Suggestions :**\n";
            $reply .= "• 'moins de " . number_format($max, 0, ',', ' ') . "' - budget max\n";
            $reply .= "• 'plus de " . number_format($min, 0, ',', ' ') . "' - budget min\n";
            $reply .= "• 'hôtel moins cher' - meilleurs prix";
            
            return response()->json(['type' => 'text', 'reply' => $reply]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 **Hôtels entre " . number_format($min, 0, ',', ' ') . " et " . number_format($max, 0, ',', ' ') . " {$currency} :**",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    /**
     * BUDGET MAX
     */
    private function maxBudgetResponse(Collection $hotels, int $max, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', '<=', $max);
        
        if ($filtered->isEmpty()) {
            $minPrice = $hotels->min('price');
            $reply = "😕 **Aucun hôtel trouvé à moins de " . number_format($max, 0, ',', ' ') . " {$currency}**\n\n";
            $reply .= "💡 Le prix le moins cher est " . number_format($minPrice, 0, ',', ' ') . " {$currency}\n";
            $reply .= "Essayez 'plus de " . number_format($minPrice, 0, ',', ' ') . "' ou 'hôtel moins cher'";
            
            return response()->json(['type' => 'text', 'reply' => $reply]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 **Hôtels à moins de " . number_format($max, 0, ',', ' ') . " {$currency} :**",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    /**
     * BUDGET MIN
     */
    private function minBudgetResponse(Collection $hotels, int $min, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', '>=', $min);
        
        if ($filtered->isEmpty()) {
            $maxPrice = $hotels->max('price');
            $reply = "😕 **Aucun hôtel trouvé à plus de " . number_format($min, 0, ',', ' ') . " {$currency}**\n\n";
            $reply .= "💡 Le prix le plus cher est " . number_format($maxPrice, 0, ',', ' ') . " {$currency}\n";
            $reply .= "Essayez 'moins de " . number_format($maxPrice, 0, ',', ' ') . "' ou 'hôtel plus cher'";
            
            return response()->json(['type' => 'text', 'reply' => $reply]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 **Hôtels à plus de " . number_format($min, 0, ',', ' ') . " {$currency} :**",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    /**
     * HÔTELS MOINS CHERS
     */
    private function cheapestHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "📊 Aucun hôtel disponible."]);
        }
        
        $cheapest = $hotels->sortBy('price')->take(3);
        
        return response()->json([
            'type' => 'hotels',
            'count' => $cheapest->count(),
            'message' => "🏨 **Voici les hôtels les moins chers :**",
            'data' => $this->formatHotels($cheapest)
        ]);
    }

    /**
     * HÔTELS PLUS CHERS
     */
    private function expensiveHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "📊 Aucun hôtel disponible."]);
        }
        
        $expensive = $hotels->sortByDesc('price')->take(3);
        
        return response()->json([
            'type' => 'hotels',
            'count' => $expensive->count(),
            'message' => "🏨 **Voici les hôtels les plus chers :**",
            'data' => $this->formatHotels($expensive)
        ]);
    }

    /**
     * PAR ZONE/QUARTIER
     */
    private function zoneHotelsResponse(Collection $hotels, string $zone, string $currency): JsonResponse
    {
        $filtered = $hotels->filter(function ($hotel) use ($zone) {
            return str_contains($hotel->address, $zone);
        });
        
        if ($filtered->isEmpty()) {
            $zones = $this->getUniqueZones($hotels);
            $zoneList = !empty($zones) ? implode(', ', $zones) : 'aucun quartier détecté';
            return response()->json([
                'type' => 'text',
                'reply' => "📍 **Aucun hôtel trouvé à {$zone}**\n\n💡 **Quartiers disponibles :** " . $zoneList
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "📍 **Hôtels situés à {$zone} :**",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    /**
     * PAR ADRESSE
     */
    private function addressHotelsResponse(Collection $hotels, string $address, string $currency): JsonResponse
    {
        $filtered = $hotels->filter(function ($hotel) use ($address) {
            return str_contains(strtolower($hotel->address), strtolower($address));
        });
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📍 **Aucun hôtel trouvé à l'adresse '{$address}'**\n\n💡 Essayez par quartier ex: 'hôtel à Dakar'"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "📍 **Hôtels correspondant à l'adresse :**",
            'data' => $this->formatHotels($filtered)
        ]);
    }

    /**
     * PAR CONTACT (TÉLÉPHONE/EMAIL)
     */
    private function contactHotelsResponse(Collection $hotels, string $contact, string $currency): JsonResponse
    {
        if ($contact === 'contact') {
            return response()->json([
                'type' => 'text',
                'reply' => "📞 **Pour obtenir les coordonnées d'un hôtel :**\n\nDonnez-moi le nom exact ou tapez 'tous les hôtels' pour voir la liste"
            ]);
        }
        
        $filtered = $hotels->filter(function ($hotel) use ($contact) {
            return (str_contains($hotel->phone, $contact)) ||
                   (str_contains($hotel->email, $contact));
        });
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📞 **Aucun contact trouvé pour '{$contact}'**\n\n💡 Tapez 'tous les hôtels' pour voir la liste des contacts disponibles"
            ]);
        }
        
        $reply = "📞 **Hôtel(s) trouvé(s) :**\n\n";
        foreach ($filtered as $hotel) {
            $reply .= "🏨 **{$hotel->name}**\n";
            $reply .= "📞 Tél : {$hotel->phone}\n";
            $reply .= "📧 Email : {$hotel->email}\n\n";
        }
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * PAR NOM
     */
    private function hotelByNameResponse(Collection $hotels, string $name, string $currency): JsonResponse
    {
        $filtered = $hotels->filter(function ($hotel) use ($name) {
            return str_contains(strtolower($hotel->name), $name);
        });
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 **Aucun hôtel nommé '{$name}' trouvé**\n\n💡 Tapez 'tous les hôtels' pour voir la liste"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 **Hôtel(s) trouvé(s) :**",
            'data' => $this->formatHotels($filtered)
        ]);
    }

    /**
     * INFO PRIX
     */
    private function priceInfoResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "📊 Aucun hôtel disponible."]);
        }
        
        $min = number_format($hotels->min('price'), 0, ',', ' ');
        $max = number_format($hotels->max('price'), 0, ',', ' ');
        $avg = number_format(round($hotels->avg('price')), 0, ',', ' ');
        
        $reply = "💰 **Informations sur les prix**\n\n";
        $reply .= "• Prix minimum : **{$min} {$currency}**\n";
        $reply .= "• Prix maximum : **{$max} {$currency}**\n";
        $reply .= "• Prix moyen : **{$avg} {$currency}**\n\n";
        $reply .= "💡 Tapez 'hôtel moins cher' pour voir les meilleures offres !";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * INFO ZONES
     */
    private function zoneInfoResponse(Collection $hotels): JsonResponse
    {
        $zones = $this->getUniqueZones($hotels);
        
        if (empty($zones)) {
            return response()->json(['type' => 'text', 'reply' => "📍 Aucun quartier détecté dans vos hôtels."]);
        }
        
        $reply = "📍 **Quartiers disponibles**\n\n";
        $reply .= "• " . implode("\n• ", $zones) . "\n\n";
        $reply .= "💡 Essayez : 'hôtel à " . $zones[0] . "'";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * HÔTELS PROCHES (GÉOLOCALISATION)
     */
    private function nearbyHotelsResponse(Collection $hotels, float $lat, float $lng, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "📊 Aucun hôtel disponible."]);
        }
        
        // Calculer les distances si les coordonnées sont disponibles
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
                'reply' => "🗺️ **Géolocalisation non disponible**\n\nAjoutez les coordonnées GPS (latitude/longitude) à vos hôtels pour utiliser cette fonctionnalité."
            ]);
        }
        
        $reply = "🗺️ **Hôtels les plus proches de vous :**\n\n";
        foreach ($hotelsWithDistance as $hotel) {
            $reply .= "🏨 **{$hotel->name}**\n";
            $reply .= "📍 Distance : ~{$hotel->distance} km\n";
            $reply .= "💰 Prix : " . number_format($hotel->price, 0, ',', ' ') . " {$currency}\n\n";
        }
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * TOUS LES HÔTELS
     */
    private function allHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📊 **Aucun hôtel disponible**\n\nCommencez par ajouter des hôtels depuis votre tableau de bord !"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $hotels->count(),
            'message' => "🏨 **Liste de tous vos hôtels (" . $hotels->count() . ") :**",
            'data' => $this->formatHotels($hotels->sortBy('price'))
        ]);
    }

    /**
     * FORMATAGE DES HÔTELS
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
                'latitude' => $hotel->latitude,
                'longitude' => $hotel->longitude,
            ];
        })->values()->toArray();
    }
}