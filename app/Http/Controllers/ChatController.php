<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Hotel;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Point d'entrée principal du chat - Assistant hôtelier
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $message = strtolower(trim($request->message));
        $userId = $request->user()->id;
        $userCurrency = $request->user()->currency ?? 'FCFA';

        // Récupérer les hôtels de l'utilisateur
        $hotels = Hotel::where('user_id', $userId)->get();
        
        // Statistiques pour l'assistant
        $stats = [
            'total' => $hotels->count(),
            'min_price' => $hotels->min('price'),
            'max_price' => $hotels->max('price'),
            'zones' => $this->getUniqueZones($hotels)
        ];

        // Traitement intelligent du message
        return $this->processMessage($message, $hotels, $userCurrency, $stats);
    }

    /**
     * Traite le message de l'utilisateur
     */
    private function processMessage(string $message, Collection $hotels, string $currency, array $stats): JsonResponse
    {
        // === 1. SALUTATIONS ===
        if ($this->isGreeting($message)) {
            return $this->greetingResponse($stats);
        }
        
        // === 2. REMERCIEMENTS ===
        if ($this->isThankYou($message)) {
            return $this->thankYouResponse();
        }
        
        // === 3. DEMANDE D'AIDE ===
        if ($this->isHelpRequest($message)) {
            return $this->helpResponse();
        }
        
        // === 4. QUESTION SUR LES PRIX ===
        if ($this->isPriceQuestion($message)) {
            return $this->priceInfoResponse($hotels, $currency);
        }
        
        // === 5. QUESTION SUR LES ZONES/QUARTIERS ===
        if ($this->isZoneQuestion($message)) {
            return $this->zoneInfoResponse($hotels);
        }
        
        // === 6. RECHERCHE MOINS CHER ===
        if ($this->isCheapestRequest($message)) {
            return $this->cheapestHotelsResponse($hotels, $currency);
        }
        
        // === 7. RECHERCHE PLUS CHER ===
        if ($this->isExpensiveRequest($message)) {
            return $this->expensiveHotelsResponse($hotels, $currency);
        }
        
        // === 8. RECHERCHE PAR PRIX EXACT ===
        $exactPrice = $this->extractExactPrice($message);
        if ($exactPrice !== null) {
            return $this->priceExactResponse($hotels, $exactPrice, $currency);
        }
        
        // === 9. RECHERCHE FOURCHETTE DE PRIX ===
        $priceRange = $this->extractPriceRange($message);
        if ($priceRange) {
            return $this->priceRangeResponse($hotels, $priceRange['min'], $priceRange['max'], $currency);
        }
        
        // === 10. RECHERCHE PAR BUDGET MAX ===
        $maxBudget = $this->extractMaxBudget($message);
        if ($maxBudget !== null) {
            return $this->maxBudgetResponse($hotels, $maxBudget, $currency);
        }
        
        // === 11. RECHERCHE PAR BUDGET MIN ===
        $minBudget = $this->extractMinBudget($message);
        if ($minBudget !== null) {
            return $this->minBudgetResponse($hotels, $minBudget, $currency);
        }
        
        // === 12. RECHERCHE PAR ZONE/QUARTIER ===
        $zone = $this->extractZone($message);
        if ($zone) {
            return $this->zoneHotelsResponse($hotels, $zone, $currency);
        }
        
        // === 13. RECHERCHE PAR NOM D'HÔTEL ===
        $hotelName = $this->extractHotelName($message);
        if ($hotelName) {
            return $this->hotelByNameResponse($hotels, $hotelName, $currency);
        }
        
        // === 14. AFFICHER TOUS LES HÔTELS ===
        if ($this->isListAllRequest($message)) {
            return $this->allHotelsResponse($hotels, $currency);
        }
        
        // === 15. RÉPONSE PAR DÉFAUT (aide) ===
        return $this->defaultHelpResponse();
    }

    /**
     * Vérifie si c'est une salutation
     */
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

    /**
     * Vérifie si c'est un remerciement
     */
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

    /**
     * Vérifie si c'est une demande d'aide
     */
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

    /**
     * Vérifie si c'est une question sur les prix
     */
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

    /**
     * Vérifie si c'est une question sur les zones
     */
    private function isZoneQuestion(string $message): bool
    {
        $zoneQuestions = ['quartier', 'zone', 'ville', 'secteur', 'où se trouvent'];
        foreach ($zoneQuestions as $zq) {
            if (str_contains($message, $zq)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vérifie si c'est une demande d'hôtel moins cher
     */
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

    /**
     * Vérifie si c'est une demande d'hôtel plus cher
     */
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

    /**
     * Vérifie si c'est une demande pour lister tous les hôtels
     */
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

    /**
     * Extrait un prix exact
     */
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
        
        return null;
    }

    /**
     * Extrait une fourchette de prix
     */
    private function extractPriceRange(string $message): ?array
    {
        preg_match('/entre\s*(\d+)\s*et\s*(\d+)/', $message, $matches);
        if (count($matches) >= 3) {
            return ['min' => (int)$matches[1], 'max' => (int)$matches[2]];
        }
        return null;
    }

    /**
     * Extrait le budget maximum
     */
    private function extractMaxBudget(string $message): ?int
    {
        if (str_contains($message, 'moins de') || str_contains($message, 'max')) {
            preg_match('/(\d+)/', $message, $matches);
            return isset($matches[1]) ? (int)$matches[1] : null;
        }
        return null;
    }

    /**
     * Extrait le budget minimum
     */
    private function extractMinBudget(string $message): ?int
    {
        if (str_contains($message, 'plus de') || str_contains($message, 'min')) {
            preg_match('/(\d+)/', $message, $matches);
            return isset($matches[1]) ? (int)$matches[1] : null;
        }
        return null;
    }

    /**
     * Extrait une zone/quartier
     */
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
     * Récupère les zones uniques des hôtels
     */
    private function getUniqueZones(Collection $hotels): array
    {
        $zones = [];
        foreach ($hotels as $hotel) {
            foreach (['Dakar', 'Ngor', 'Almadies', 'Plateau', 'Yoff', 'Saly', 'Mbour'] as $zone) {
                if (str_contains($hotel->address, $zone)) {
                    $zones[] = $zone;
                    break;
                }
            }
        }
        return array_unique($zones);
    }

    /**
     * Réponse de salutation
     */
    private function greetingResponse(array $stats): JsonResponse
    {
        $reply = "👋 Bonjour ! Je suis votre assistant hôtelier.\n\n";
        $reply .= "📊 **Votre catalogue** : {$stats['total']} hôtels\n";
        $reply .= "💰 **Prix** : de {$stats['min_price']} à {$stats['max_price']} FCFA\n";
        
        if (!empty($stats['zones'])) {
            $reply .= "📍 **Zones** : " . implode(', ', $stats['zones']) . "\n\n";
        }
        
        $reply .= "🔍 **Que puis-je faire pour vous ?**\n";
        $reply .= "• Trouver un hôtel moins cher\n";
        $reply .= "• Chercher par budget (ex: 'moins de 30000')\n";
        $reply .= "• Chercher par quartier (ex: 'hôtel à Dakar')\n";
        $reply .= "• Liste de tous mes hôtels\n\n";
        $reply .= "💡 Tapez **aide** pour plus d'exemples";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse de remerciement
     */
    private function thankYouResponse(): JsonResponse
    {
        $replies = [
            "Avec plaisir ! 😊 N'hésitez pas si je peux vous aider à trouver un hôtel.",
            "Je vous en prie ! 🎉 À votre service pour trouver l'hôtel idéal.",
            "Service ! ✨ Besoin d'autre chose ? Un hôtel pas cher, un quartier spécifique ?"
        ];
        return response()->json(['type' => 'text', 'reply' => $replies[array_rand($replies)]]);
    }

    /**
     * Réponse d'aide complète
     */
    private function helpResponse(): JsonResponse
    {
        $reply = "📚 **Guide d'utilisation**\n\n";
        $reply .= "**Recherches possibles :**\n";
        $reply .= "• 'hôtel moins cher' → les 3 meilleurs prix\n";
        $reply .= "• 'hôtel à 25000' → prix exact\n";
        $reply .= "• 'moins de 30000' → budget maximum\n";
        $reply .= "• 'plus de 50000' → budget minimum\n";
        $reply .= "• 'entre 20000 et 50000' → fourchette de prix\n";
        $reply .= "• 'hôtel à Dakar' → par quartier/zone\n";
        $reply .= "• 'hôtel Terrou' → par nom\n";
        $reply .= "• 'tous les hôtels' → liste complète\n\n";
        $reply .= "**Questions possibles :**\n";
        $reply .= "• Quels sont les prix ?\n";
        $reply .= "• Dans quels quartiers avez-vous des hôtels ?";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse info prix
     */
    private function priceInfoResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "📊 Vous n'avez pas encore d'hôtels. Commencez par en ajouter quelques-uns !"]);
        }
        
        $min = $hotels->min('price');
        $max = $hotels->max('price');
        $avg = round($hotels->avg('price'));
        
        $reply = "💰 **Informations sur les prix**\n";
        $reply .= "• Prix minimum : **{$min} {$currency}**\n";
        $reply .= "• Prix maximum : **{$max} {$currency}**\n";
        $reply .= "• Prix moyen : **{$avg} {$currency}**\n\n";
        $reply .= "💡 Tapez 'hôtel moins cher' pour voir les meilleures offres !";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse info zones
     */
    private function zoneInfoResponse(Collection $hotels): JsonResponse
    {
        $zones = $this->getUniqueZones($hotels);
        
        if (empty($zones)) {
            return response()->json(['type' => 'text', 'reply' => "📍 Je n'ai pas encore détecté de quartiers. Ajoutez des adresses à vos hôtels !"]);
        }
        
        $reply = "📍 **Quartiers disponibles**\n";
        $reply .= "• " . implode("\n• ", $zones) . "\n\n";
        $reply .= "💡 Essayez : 'hôtel à " . $zones[0] . "' pour voir les hôtels dans ce quartier !";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse hôtels moins chers
     */
    private function cheapestHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "📊 Vous n'avez pas encore d'hôtels."]);
        }
        
        $cheapest = $hotels->sortBy('price')->take(3);
        
        if ($cheapest->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "😕 Aucun hôtel disponible."]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $cheapest->count(),
            'message' => "🏨 Voici les {$cheapest->count()} hôtels les moins chers :",
            'data' => $this->formatHotels($cheapest)
        ]);
    }

    /**
     * Réponse hôtels plus chers
     */
    private function expensiveHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json(['type' => 'text', 'reply' => "📊 Vous n'avez pas encore d'hôtels."]);
        }
        
        $expensive = $hotels->sortByDesc('price')->take(3);
        
        return response()->json([
            'type' => 'hotels',
            'count' => $expensive->count(),
            'message' => "🏨 Voici les {$expensive->count()} hôtels les plus chers :",
            'data' => $this->formatHotels($expensive)
        ]);
    }

    /**
     * Réponse prix exact
     */
    private function priceExactResponse(Collection $hotels, int $price, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', $price);
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel trouvé à **{$price} {$currency}**.\n\n" .
                           "💡 Suggestions :\n" .
                           "• 'moins de {$price}' - pour un budget inférieur\n" .
                           "• 'plus de {$price}' - pour un budget supérieur\n" .
                           "• 'hôtel moins cher' - pour voir les meilleurs prix"
            ]);
        }
        
        $message = $filtered->count() === 1 
            ? "🏨 Hôtel trouvé à {$price} {$currency} :" 
            : "🏨 {$filtered->count()} hôtels trouvés à {$price} {$currency} :";
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => $message,
            'data' => $this->formatHotels($filtered)
        ]);
    }

    /**
     * Réponse fourchette de prix
     */
    private function priceRangeResponse(Collection $hotels, int $min, int $max, string $currency): JsonResponse
    {
        $filtered = $hotels->whereBetween('price', [$min, $max]);
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel trouvé entre **{$min}** et **{$max} {$currency}**.\n\n" .
                           "💡 Essayez une fourchette plus large ou tapez 'hôtel moins cher'"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtels entre {$min} et {$max} {$currency} :",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    /**
     * Réponse budget max
     */
    private function maxBudgetResponse(Collection $hotels, int $max, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', '<=', $max);
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel trouvé à moins de **{$max} {$currency}**.\n" .
                           "💡 Essayez d'augmenter votre budget ou tapez 'tous les hôtels'"
            ]);
        }
        
        $cheapestFirst = $filtered->sortBy('price');
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtels à moins de {$max} {$currency} (du moins cher au plus cher) :",
            'data' => $this->formatHotels($cheapestFirst)
        ]);
    }

    /**
     * Réponse budget min
     */
    private function minBudgetResponse(Collection $hotels, int $min, string $currency): JsonResponse
    {
        $filtered = $hotels->where('price', '>=', $min);
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel trouvé à plus de **{$min} {$currency}**.\n" .
                           "💡 Essayez de diminuer votre budget ou tapez 'tous les hôtels'"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtels à plus de {$min} {$currency} :",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    /**
     * Réponse par zone/quartier
     */
    private function zoneHotelsResponse(Collection $hotels, string $zone, string $currency): JsonResponse
    {
        $filtered = $hotels->filter(function ($hotel) use ($zone) {
            return str_contains($hotel->address, $zone);
        });
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📍 Aucun hôtel trouvé à **{$zone}**.\n\n" .
                           "💡 Quartiers disponibles : " . implode(', ', $this->getUniqueZones($hotels))
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "📍 Hôtels situés à {$zone} :",
            'data' => $this->formatHotels($filtered->sortBy('price'))
        ]);
    }

    /**
     * Réponse par nom d'hôtel
     */
    private function hotelByNameResponse(Collection $hotels, string $name, string $currency): JsonResponse
    {
        $filtered = $hotels->filter(function ($hotel) use ($name) {
            return str_contains(strtolower($hotel->name), $name);
        });
        
        if ($filtered->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Aucun hôtel nommé \"{$name}\" n'a été trouvé.\n💡 Tapez 'tous les hôtels' pour voir la liste complète"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $filtered->count(),
            'message' => "🏨 Hôtel(s) trouvé(s) :",
            'data' => $this->formatHotels($filtered)
        ]);
    }

    /**
     * Réponse avec tous les hôtels
     */
    private function allHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📊 Vous n'avez pas encore d'hôtels. Commencez par en ajouter quelques-uns !"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $hotels->count(),
            'message' => "🏨 Liste de tous vos hôtels ({$hotels->count()}) :",
            'data' => $this->formatHotels($hotels->sortBy('price'))
        ]);
    }

    /**
     * Réponse d'aide par défaut
     */
    private function defaultHelpResponse(): JsonResponse
    {
        return response()->json([
            'type' => 'text',
            'reply' => "🔍 **Je suis votre assistant hôtelier !**\n\n" .
                       "Voici ce que je peux faire pour vous :\n\n" .
                       "💰 **Recherche par prix :**\n" .
                       "• 'hôtel moins cher' - meilleurs prix\n" .
                       "• 'hôtel à 25000' - prix exact\n" .
                       "• 'moins de 30000' - budget max\n" .
                       "• 'plus de 50000' - budget min\n" .
                       "• 'entre 20000 et 50000' - fourchette\n\n" .
                       "📍 **Recherche par quartier :**\n" .
                       "• 'hôtel à Dakar', 'hôtel à Ngor'\n\n" .
                       "📋 **Autres commandes :**\n" .
                       "• 'tous les hôtels', 'aide', 'prix', 'quartiers'\n\n" .
                       "💬 **Posez votre question en langage naturel !**"
        ]);
    }

    /**
     * Formate les hôtels pour la réponse
     */
    private function formatHotels(Collection $hotels): array
    {
        return $hotels->map(function ($hotel) {
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
        })->values()->toArray();
    }
}