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
     * Point d'entrée principal - Assistant hôtelier professionnel
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();
        $message = trim($request->message);
        $currency = $user->currency ?? 'FCFA';
        
        // Récupérer les hôtels
        $hotels = Hotel::where('user_id', $user->id)->get();

        // Conversation intelligente
        return $this->smartResponse($message, $hotels, $currency);
    }

    /**
     * Réponse intelligente selon le contexte
     */
    private function smartResponse(string $message, Collection $hotels, string $currency): JsonResponse
    {
        $msg = strtolower($message);
        
        // === 1. SALUTATION (Message d'accueil chaleureux) ===
        if ($this->isGreeting($msg)) {
            return $this->greetingResponse($hotels, $currency);
        }
        
        // === 2. REMERCIEMENT ===
        if ($this->isThankYou($msg)) {
            return $this->thankYouResponse();
        }
        
        // === 3. DEMANDE D'AIDE ===
        if ($this->isHelpRequest($msg)) {
            return $this->helpResponse();
        }
        
        // === 4. DEMANDE "HÔTEL MOINS CHER" ===
        if ($this->isCheapestRequest($msg)) {
            return $this->cheapestHotelsResponse($hotels, $currency);
        }
        
        // === 5. DEMANDE "HÔTEL PLUS CHER" ===
        if ($this->isExpensiveRequest($msg)) {
            return $this->expensiveHotelsResponse($hotels, $currency);
        }
        
        // === 6. PRIX EXACT ===
        $exactPrice = $this->extractExactPrice($msg);
        if ($exactPrice !== null) {
            return $this->priceExactResponse($hotels, $exactPrice, $currency);
        }
        
        // === 7. BUDGET MAX ===
        $maxBudget = $this->extractMaxBudget($msg);
        if ($maxBudget !== null) {
            return $this->maxBudgetResponse($hotels, $maxBudget, $currency);
        }
        
        // === 8. FOURCHETTE DE PRIX ===
        $priceRange = $this->extractPriceRange($msg);
        if ($priceRange !== null) {
            return $this->priceRangeResponse($hotels, $priceRange['min'], $priceRange['max'], $currency);
        }
        
        // === 9. RECHERCHE PAR ZONE ===
        $zone = $this->extractZone($msg);
        if ($zone !== null) {
            return $this->zoneHotelsResponse($hotels, $zone, $currency);
        }
        
        // === 10. RECHERCHE PAR NOM ===
        $hotelName = $this->extractHotelName($msg);
        if ($hotelName !== null) {
            return $this->hotelByNameResponse($hotels, $hotelName, $currency);
        }
        
        // === 11. DEMANDE DE CONTACT ===
        if ($this->isContactRequest($msg)) {
            return $this->contactRequestResponse($hotels, $msg, $currency);
        }
        
        // === 12. DEMANDE "TOUS LES HÔTELS" ===
        if ($this->isListAllRequest($msg)) {
            return $this->allHotelsResponse($hotels, $currency);
        }
        
        // === 13. QUESTION GÉNÉRALE (prix, zones) ===
        if ($this->isGeneralQuestion($msg)) {
            return $this->generalInfoResponse($hotels, $currency);
        }
        
        // === 14. MESSAGE NON RECONNU ===
        return $this->unknownIntentResponse();
    }

    // ========== MÉTHODES DE DÉTECTION ==========

    private function isGreeting(string $msg): bool
    {
        $greetings = ['bonjour', 'salut', 'coucou', 'hello', 'hi', 'hey', 'bonsoir', 'yo'];
        foreach ($greetings as $greeting) {
            if (str_contains($msg, $greeting)) {
                return true;
            }
        }
        return false;
    }

    private function isThankYou(string $msg): bool
    {
        $thanks = ['merci', 'thanks', 'thank you', 'super', 'génial', 'parfait'];
        foreach ($thanks as $thank) {
            if (str_contains($msg, $thank)) {
                return true;
            }
        }
        return false;
    }

    private function isHelpRequest(string $msg): bool
    {
        $helps = ['aide', 'help', 'que peux-tu faire', 'comment ça marche'];
        foreach ($helps as $help) {
            if (str_contains($msg, $help)) {
                return true;
            }
        }
        return false;
    }

    private function isCheapestRequest(string $msg): bool
    {
        $keywords = ['moins cher', 'pas cher', 'économique', 'budget', 'le moins cher', 'meilleur prix', 'low cost'];
        foreach ($keywords as $word) {
            if (str_contains($msg, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isExpensiveRequest(string $msg): bool
    {
        $keywords = ['plus cher', 'luxe', 'haut de gamme', 'premium', 'le plus cher'];
        foreach ($keywords as $word) {
            if (str_contains($msg, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isListAllRequest(string $msg): bool
    {
        $keywords = ['tous les hôtels', 'liste des hôtels', 'affiche tout', 'tous mes hôtels', 'tous les hotels', 'tous'];
        foreach ($keywords as $word) {
            if (str_contains($msg, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isContactRequest(string $msg): bool
    {
        $keywords = ['contact', 'téléphone', 'tel', 'phone', 'coordonnées', 'appeler'];
        foreach ($keywords as $word) {
            if (str_contains($msg, $word)) {
                return true;
            }
        }
        return false;
    }

    private function isGeneralQuestion(string $msg): bool
    {
        $keywords = ['prix', 'tarif', 'combien', 'quartier', 'zone', 'ville'];
        foreach ($keywords as $word) {
            if (str_contains($msg, $word)) {
                return true;
            }
        }
        return false;
    }

    // ========== MÉTHODES D'EXTRACTION ==========

    private function extractExactPrice(string $msg): ?int
    {
        if (preg_match('/(?:[àa]|prix)\s*(\d+)/', $msg, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    private function extractMaxBudget(string $msg): ?int
    {
        if (preg_match('/(?:moins de|max|maximum|<)\s*(\d+)/', $msg, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    private function extractPriceRange(string $msg): ?array
    {
        if (preg_match('/entre\s*(\d+)\s*et\s*(\d+)/', $msg, $matches)) {
            return ['min' => (int)$matches[1], 'max' => (int)$matches[2]];
        }
        return null;
    }

    private function extractZone(string $msg): ?string
    {
        $zones = ['dakar', 'ngor', 'almadie', 'plateau', 'yoff', 'saly', 'mbour'];
        foreach ($zones as $zone) {
            if (str_contains($msg, $zone)) {
                return ucfirst($zone);
            }
        }
        return null;
    }

    private function extractHotelName(string $msg): ?string
    {
        $hotelNames = ['rade', 'terrou', 'radisson', 'king fahd', 'pullman'];
        foreach ($hotelNames as $name) {
            if (str_contains($msg, $name)) {
                return $name;
            }
        }
        return null;
    }

    // ========== RÉPONSES PROFESSIONNELLES ==========

    /**
     * Réponse de salutation professionnelle
     */
    private function greetingResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "👋 **Bonjour et bienvenue !**\n\nJe suis votre assistant hôtelier Red Product.\n\n📊 Vous n'avez pas encore d'hôtels dans votre catalogue.\n\n💡 **Pour commencer :**\n• Ajoutez des hôtels depuis votre tableau de bord\n• Posez-moi des questions sur vos hôtels\n\n🔍 **Exemple :** \"liste de mes hôtels\"\n\nComment puis-je vous aider aujourd'hui ?"
            ]);
        }

        $minPrice = number_format($hotels->min('price'), 0, ',', ' ');
        $maxPrice = number_format($hotels->max('price'), 0, ',', ' ');
        $count = $hotels->count();
        
        $reply = "👋 **Bonjour !**\n\n";
        $reply .= "Je suis votre assistant hôtelier Red Product. 🤖\n\n";
        $reply .= "📊 **Votre catalogue :** {$count} hôtels\n";
        $reply .= "💰 **Prix :** de {$minPrice} à {$maxPrice} {$currency}\n\n";
        $reply .= "🔍 **Que puis-je faire pour vous ?**\n";
        $reply .= "• 🏨 Voir les **hôtels moins chers**\n";
        $reply .= "• 💰 Chercher par **prix exact** (ex: \"hôtel à 25000\")\n";
        $reply .= "• 📍 Chercher par **quartier** (ex: \"hôtel à Dakar\")\n";
        $reply .= "• 📋 Afficher **tous mes hôtels**\n";
        $reply .= "• 📞 Obtenir les **coordonnées** d'un hôtel\n\n";
        $reply .= "💡 **Tapez 'aide'** pour voir tous les exemples.\n\n";
        $reply .= "Comment puis-je vous aider aujourd'hui ?";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse de remerciement
     */
    private function thankYouResponse(): JsonResponse
    {
        $replies = [
            "Avec plaisir ! 😊 Je reste à votre disposition.",
            "Je vous en prie ! 🎉 N'hésitez pas si vous avez d'autres questions.",
            "Service ! ✨ Besoin d'autre chose ? Je suis là pour vous aider."
        ];
        return response()->json(['type' => 'text', 'reply' => $replies[array_rand($replies)]]);
    }

    /**
     * Réponse d'aide complète
     */
    private function helpResponse(): JsonResponse
    {
        $reply = "📚 **Guide d'utilisation - Assistant Red Product** 📚\n\n";
        $reply .= "💰 **RECHERCHE PAR PRIX :**\n";
        $reply .= "• \"hôtel moins cher\" → les 3 meilleurs prix\n";
        $reply .= "• \"hôtel plus cher\" → les 3 prix les plus élevés\n";
        $reply .= "• \"hôtel à 25000\" → prix exact\n";
        $reply .= "• \"moins de 30000\" → budget maximum\n";
        $reply .= "• \"entre 20000 et 50000\" → fourchette de prix\n\n";
        
        $reply .= "📍 **RECHERCHE PAR LIEU :**\n";
        $reply .= "• \"hôtel à Dakar\" → par quartier\n";
        $reply .= "• \"hôtel à Ngor\" → par quartier\n\n";
        
        $reply .= "🏨 **RECHERCHE PAR NOM :**\n";
        $reply .= "• \"Terrou-Bi\" → informations sur cet hôtel\n\n";
        
        $reply .= "📞 **CONTACT :**\n";
        $reply .= "• \"contact Terrou-Bi\" → téléphone et email\n\n";
        
        $reply .= "📋 **LISTE :**\n";
        $reply .= "• \"tous mes hôtels\" → catalogue complet\n";
        $reply .= "• \"prix\" → informations sur les prix\n";
        $reply .= "• \"quartiers\" → zones disponibles\n\n";
        
        $reply .= "💬 **Posez votre question naturellement, je vous comprends !**";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse pour message non reconnu
     */
    private function unknownIntentResponse(): JsonResponse
    {
        $reply = "🤔 **Je n'ai pas bien compris votre demande.**\n\n";
        $reply .= "📝 **Voici ce que je peux faire :**\n";
        $reply .= "• 🔍 Rechercher des hôtels par prix\n";
        $reply .= "• 📍 Rechercher par quartier\n";
        $reply .= "• 📞 Donner les coordonnées d'un hôtel\n";
        $reply .= "• 📋 Lister tous vos hôtels\n\n";
        $reply .= "💡 **Tapez 'aide'** pour voir tous les exemples.\n\n";
        $reply .= "Comment puis-je vous aider ?";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse pour "hôtel moins cher"
     */
    private function cheapestHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📊 Vous n'avez pas encore d'hôtels. Commencez par en ajouter depuis votre tableau de bord !"
            ]);
        }
        
        $cheapest = $hotels->sortBy('price')->take(3);
        $cheapestPrice = number_format($cheapest->first()->price, 0, ',', ' ');
        
        $reply = "💰 **Voici les hôtels les moins chers :**\n\n";
        $reply .= "🏨 **Prix le plus bas :** {$cheapestPrice} {$currency}\n\n";
        
        return response()->json([
            'type' => 'hotels',
            'count' => $cheapest->count(),
            'message' => $reply,
            'data' => $this->formatHotels($cheapest)
        ]);
    }

    /**
     * Réponse pour "hôtel plus cher"
     */
    private function expensiveHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📊 Vous n'avez pas encore d'hôtels."
            ]);
        }
        
        $expensive = $hotels->sortByDesc('price')->take(3);
        $expensivePrice = number_format($expensive->first()->price, 0, ',', ' ');
        
        $reply = "💎 **Voici les hôtels les plus chers :**\n\n";
        $reply .= "🏨 **Prix le plus élevé :** {$expensivePrice} {$currency}\n\n";
        
        return response()->json([
            'type' => 'hotels',
            'count' => $expensive->count(),
            'message' => $reply,
            'data' => $this->formatHotels($expensive)
        ]);
    }

    /**
     * Réponse pour prix exact
     */
    private function priceExactResponse(Collection $hotels, int $price, string $currency): JsonResponse
    {
        $formattedPrice = number_format($price, 0, ',', ' ');
        $exactMatch = $hotels->where('price', $price);
        
        if ($exactMatch->isNotEmpty()) {
            $reply = "💰 **Hôtel(s) trouvé(s) à {$formattedPrice} {$currency} :**\n\n";
            return response()->json([
                'type' => 'hotels',
                'count' => $exactMatch->count(),
                'message' => $reply,
                'data' => $this->formatHotels($exactMatch)
            ]);
        }
        
        // Trouver les prix les plus proches
        $closestLower = $hotels->where('price', '<', $price)->sortByDesc('price')->first();
        $closestHigher = $hotels->where('price', '>', $price)->sortBy('price')->first();
        
        $reply = "😕 **Aucun hôtel trouvé à {$formattedPrice} {$currency}**\n\n";
        $reply .= "💡 **Prix les plus proches :**\n";
        
        if ($closestLower) {
            $reply .= "• Moins cher : " . number_format($closestLower->price, 0, ',', ' ') . " {$currency} - {$closestLower->name}\n";
        }
        if ($closestHigher) {
            $reply .= "• Plus cher : " . number_format($closestHigher->price, 0, ',', ' ') . " {$currency} - {$closestHigher->name}\n";
        }
        
        $reply .= "\n📝 **Suggestions :**\n";
        $reply .= "• \"hôtel moins cher\" - pour voir les meilleurs prix\n";
        $reply .= "• \"tous les hôtels\" - pour la liste complète";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse pour budget max
     */
    private function maxBudgetResponse(Collection $hotels, int $max, string $currency): JsonResponse
    {
        $formattedMax = number_format($max, 0, ',', ' ');
        $result = $hotels->where('price', '<=', $max)->sortBy('price');
        
        if ($result->isEmpty()) {
            $minPrice = number_format($hotels->min('price'), 0, ',', ' ');
            return response()->json([
                'type' => 'text',
                'reply' => "😕 **Aucun hôtel trouvé à moins de {$formattedMax} {$currency}**\n\n💡 Le prix minimum est {$minPrice} {$currency}\n\nEssayez \"hôtel moins cher\" ou \"tous les hôtels\""
            ]);
        }
        
        $reply = "💰 **Hôtels à moins de {$formattedMax} {$currency} ({$result->count()}) :**\n\n";
        $reply .= "🏨 Du moins cher au plus cher :\n";
        
        return response()->json([
            'type' => 'hotels',
            'count' => $result->count(),
            'message' => $reply,
            'data' => $this->formatHotels($result)
        ]);
    }

    /**
     * Réponse pour fourchette de prix
     */
    private function priceRangeResponse(Collection $hotels, int $min, int $max, string $currency): JsonResponse
    {
        $formattedMin = number_format($min, 0, ',', ' ');
        $formattedMax = number_format($max, 0, ',', ' ');
        $result = $hotels->whereBetween('price', [$min, $max])->sortBy('price');
        
        if ($result->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 **Aucun hôtel trouvé entre {$formattedMin} et {$formattedMax} {$currency}**\n\n💡 Essayez une fourchette plus large ou tapez \"tous les hôtels\""
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $result->count(),
            'message' => "🏨 **Hôtels entre {$formattedMin} et {$formattedMax} {$currency} :**",
            'data' => $this->formatHotels($result)
        ]);
    }

    /**
     * Réponse pour recherche par zone
     */
    private function zoneHotelsResponse(Collection $hotels, string $zone, string $currency): JsonResponse
    {
        $result = $hotels->filter(function ($hotel) use ($zone) {
            return str_contains(strtolower($hotel->address), strtolower($zone));
        })->sortBy('price');
        
        if ($result->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📍 **Aucun hôtel trouvé à {$zone}**\n\n💡 Essayez un autre quartier ou tapez \"tous les hôtels\" pour voir toutes les adresses"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $result->count(),
            'message' => "📍 **Hôtels situés à {$zone} ({$result->count()}) :**",
            'data' => $this->formatHotels($result)
        ]);
    }

    /**
     * Réponse pour recherche par nom
     */
    private function hotelByNameResponse(Collection $hotels, string $name, string $currency): JsonResponse
    {
        $result = $hotels->filter(function ($hotel) use ($name) {
            return str_contains(strtolower($hotel->name), strtolower($name));
        });
        
        if ($result->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 **Aucun hôtel nommé '{$name}' trouvé**\n\n💡 Tapez \"tous les hôtels\" pour voir la liste complète"
            ]);
        }
        
        return response()->json([
            'type' => 'hotels',
            'count' => $result->count(),
            'message' => "🏨 **Informations sur {$name} :**",
            'data' => $this->formatHotels($result)
        ]);
    }

    /**
     * Réponse pour demande de contact
     */
    private function contactRequestResponse(Collection $hotels, string $msg, string $currency): JsonResponse
    {
        // Essayer de trouver un nom d'hôtel dans la phrase
        $hotelNames = ['rade', 'terrou', 'radisson', 'king fahd', 'pullman'];
        $foundHotel = null;
        
        foreach ($hotelNames as $name) {
            if (str_contains($msg, $name)) {
                $foundHotel = $hotels->first(function ($hotel) use ($name) {
                    return str_contains(strtolower($hotel->name), $name);
                });
                break;
            }
        }
        
        if ($foundHotel) {
            $reply = "📞 **Coordonnées de {$foundHotel->name}**\n\n";
            $reply .= "🏨 **Nom :** {$foundHotel->name}\n";
            $reply .= "📍 **Adresse :** {$foundHotel->address}\n";
            $reply .= "📞 **Téléphone :** " . ($foundHotel->phone ?? 'Non renseigné') . "\n";
            $reply .= "📧 **Email :** " . ($foundHotel->email ?? 'Non renseigné');
            return response()->json(['type' => 'text', 'reply' => $reply]);
        }
        
        return response()->json([
            'type' => 'text',
            'reply' => "📞 **Pour obtenir les coordonnées :**\n\nDonnez-moi le nom exact (ex: \"contact Terrou-Bi\")\n\nOu tapez \"tous les hôtels\" pour voir les contacts disponibles"
        ]);
    }

    /**
     * Réponse pour liste complète
     */
    private function allHotelsResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📊 **Aucun hôtel disponible**\n\nCommencez par ajouter des hôtels depuis votre tableau de bord !"
            ]);
        }
        
        $sorted = $hotels->sortBy('price');
        $min = number_format($sorted->first()->price, 0, ',', ' ');
        $max = number_format($sorted->last()->price, 0, ',', ' ');
        
        $reply = "🏨 **Votre catalogue hôtelier ({$hotels->count()} hôtels)**\n\n";
        $reply .= "💰 **Prix :** de {$min} à {$max} {$currency}\n\n";
        $reply .= "📋 **Liste complète :**\n";
        
        return response()->json([
            'type' => 'hotels',
            'count' => $sorted->count(),
            'message' => $reply,
            'data' => $this->formatHotels($sorted)
        ]);
    }

    /**
     * Réponse pour questions générales
     */
    private function generalInfoResponse(Collection $hotels, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'text',
                'reply' => "📊 Aucun hôtel disponible. Commencez par en ajouter !"
            ]);
        }
        
        $min = number_format($hotels->min('price'), 0, ',', ' ');
        $max = number_format($hotels->max('price'), 0, ',', ' ');
        $avg = number_format(round($hotels->avg('price')), 0, ',', ' ');
        $zones = $this->getUniqueZones($hotels);
        
        $reply = "📊 **Informations sur votre catalogue**\n\n";
        $reply .= "🏨 **Nombre d'hôtels :** {$hotels->count()}\n";
        $reply .= "💰 **Prix minimum :** {$min} {$currency}\n";
        $reply .= "💰 **Prix maximum :** {$max} {$currency}\n";
        $reply .= "📈 **Prix moyen :** {$avg} {$currency}\n";
        
        if (!empty($zones)) {
            $reply .= "📍 **Quartiers :** " . implode(', ', $zones) . "\n";
        }
        
        $reply .= "\n💡 **Besoin d'aide ?** Tapez \"aide\" pour voir les exemples.";
        
        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Récupère les zones uniques
     */
    private function getUniqueZones(Collection $hotels): array
    {
        $zones = [];
        $zoneList = ['Dakar', 'Ngor', 'Almadies', 'Plateau', 'Yoff', 'Saly', 'Mbour'];
        
        foreach ($hotels as $hotel) {
            foreach ($zoneList as $zone) {
                if (str_contains($hotel->address, $zone) && !in_array($zone, $zones)) {
                    $zones[] = $zone;
                    break;
                }
            }
        }
        return $zones;
    }

    /**
     * Formatage des hôtels
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