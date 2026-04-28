<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Hotel;

class ChatController extends Controller
{
    /**
     * Point d'entrée principal du chat - Assistant naturel
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
            'city' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $message = strtolower($request->message);
        $userId = $request->user()->id;
        $userCurrency = $request->user()->currency ?? 'FCFA';

        /** @var Builder $query */
        $query = Hotel::where('user_id', $userId);
        
        // Traitement de la demande comme un vrai assistant
        $result = $this->processNaturalQuery($message, $query, $userCurrency);
        
        return $result;
    }
    
    /**
     * Traite la requête de manière naturelle
     */
    private function processNaturalQuery(string $message, Builder $query, string $userCurrency): JsonResponse
    {
        // 1. Vérifier les salutations
        if ($this->isGreeting($message)) {
            return $this->naturalGreeting();
        }
        
        // 2. Remerciements
        if ($this->isThankYou($message)) {
            return $this->thankYouResponse();
        }
        
        // 3. Extraire l'intention de la phrase
        $intent = $this->extractIntent($message);
        
        // 4. Appliquer les filtres en fonction de l'intention
        $hasFilter = $this->applyNaturalFilters($message, $query);
        
        // 5. Exécuter la recherche
        /** @var Collection $hotels */
        $hotels = $query->limit(10)->get();
        
        // 6. Répondre de manière naturelle
        return $this->naturalResponse($hotels, $message, $userCurrency, $intent);
    }
    
    /**
     * Vérifie si c'est une salutation
     */
    private function isGreeting(string $message): bool
    {
        $greetings = ['bonjour', 'salut', 'coucou', 'hello', 'hi', 'hey', 'bonsoir', 'bonsoir'];
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
        $thanks = ['merci', 'thanks', 'thank you', 'super', 'génial', 'parfait'];
        foreach ($thanks as $thank) {
            if (str_contains($message, $thank)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Extrait l'intention de la phrase
     */
    private function extractIntent(string $message): string
    {
        if (str_contains($message, 'prix') || str_contains($message, 'coûte') || str_contains($message, 'tarif')) {
            return 'price_query';
        }
        if (str_contains($message, 'où') || str_contains($message, 'situé') || str_contains($message, 'adresse')) {
            return 'location_query';
        }
        if (str_contains($message, 'contact') || str_contains($message, 'téléphone') || str_contains($message, 'appeler')) {
            return 'contact_query';
        }
        if (str_contains($message, 'disponible') || str_contains($message, 'libre')) {
            return 'availability_query';
        }
        return 'general_query';
    }
    
    /**
     * Applique les filtres de manière naturelle
     */
    private function applyNaturalFilters(string $message, Builder $query): bool
    {
        $hasFilter = false;
        
        // Extraction des prix
        preg_match_all('/(\d+)/', $message, $priceMatches);
        $prices = $priceMatches[0] ?? [];
        
        // Prix exact (ex: "un hôtel à 25000")
        if (str_contains($message, ' à ') && !empty($prices)) {
            $query->where('price', (int)$prices[0]);
            $hasFilter = true;
        }
        // Moins de X (ex: "moins de 30000" ou "max 30000")
        elseif ((str_contains($message, 'moins') || str_contains($message, 'max')) && !empty($prices)) {
            $query->where('price', '<=', (int)$prices[0]);
            $hasFilter = true;
        }
        // Plus de X (ex: "plus de 50000" ou "min 50000")
        elseif ((str_contains($message, 'plus') || str_contains($message, 'min')) && !empty($prices)) {
            $query->where('price', '>=', (int)$prices[0]);
            $hasFilter = true;
        }
        // Entre X et Y
        elseif (str_contains($message, 'entre') && count($prices) >= 2) {
            $query->whereBetween('price', [(int)$prices[0], (int)$prices[1]]);
            $hasFilter = true;
        }
        
        // Extraction des villes/zones
        $zones = [
            'dakar' => 'Dakar', 'ngor' => 'Ngor', 'almadie' => 'Almadies',
            'plateau' => 'Plateau', 'yoff' => 'Yoff', 'ouakam' => 'Ouakam',
            'mermoz' => 'Mermoz', 'sicap' => 'Sicap', 'liberté' => 'Liberté',
            'saly' => 'Saly', 'mbour' => 'Mbour', 'la somone' => 'La Somone',
            'lac rose' => 'Lac Rose', 'sine saloum' => 'Sine Saloum'
        ];
        
        foreach ($zones as $zoneKey => $zoneName) {
            if (str_contains($message, $zoneKey)) {
                $query->where('address', 'like', "%{$zoneName}%");
                $hasFilter = true;
                break;
            }
        }
        
        // Recherche par nom d'hôtel
        $hotelNames = ['rade', 'terrou', 'radisson', 'king fahd', 'pullman'];
        foreach ($hotelNames as $name) {
            if (str_contains($message, $name)) {
                $query->where('name', 'like', "%{$name}%");
                $hasFilter = true;
                break;
            }
        }
        
        return $hasFilter;
    }
    
    /**
     * Réponse naturelle de salutation
     */
    private function naturalGreeting(): JsonResponse
    {
        $replies = [
            "Bonjour ! 👋 Je suis votre assistant. Dites-moi ce que vous cherchez : un hôtel dans un quartier spécifique, à un certain prix, ou avec des services particuliers ?",
            "Salut ! 😊 Comment puis-je vous aider à trouver l'hôtel idéal aujourd'hui ? Donnez-moi votre budget ou le quartier souhaité.",
            "Bonjour et bienvenue ! 🌟 Je suis là pour vous aider à trouver un hôtel. Quel est votre budget ou votre quartier préféré ?"
        ];
        
        return response()->json([
            'type' => 'text',
            'reply' => $replies[array_rand($replies)]
        ]);
    }
    
    /**
     * Réponse pour les remerciements
     */
    private function thankYouResponse(): JsonResponse
    {
        $replies = [
            "Avec plaisir ! 😊 N'hésitez pas si je peux faire autre chose pour vous.",
            "Je vous en prie ! 🎉 Bonne journée et à bientôt.",
            "Service ! ✨ Si vous avez besoin d'autres informations, je suis là."
        ];
        
        return response()->json([
            'type' => 'text',
            'reply' => $replies[array_rand($replies)]
        ]);
    }
    
    /**
     * Réponse naturelle selon les résultats
     */
    private function naturalResponse(Collection $hotels, string $message, string $currency, string $intent): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return $this->noResultsResponse($message, $currency);
        }
        
        // Réponse avec résultats
        $intro = $this->getNaturalIntro($hotels->count(), $message, $currency);
        
        return response()->json([
            'type' => 'hotels',
            'count' => $hotels->count(),
            'message' => $intro,
            'data' => $hotels->map(function (Hotel $hotel) {
                return [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                    'address' => $hotel->address,
                    'price' => $hotel->price,
                    'currency' => $hotel->currency,
                    'image' => $hotel->image,
                    'phone' => $hotel->phone ?? 'Non renseigné',
                    'email' => $hotel->email ?? 'Non renseigné',
                    'description' => $hotel->description ?? ''
                ];
            })
        ]);
    }
    
    /**
     * Introduction naturelle selon le contexte
     */
    private function getNaturalIntro(int $count, string $message, string $currency): string
    {
        // Extraire le prix si présent
        preg_match('/(\d+)/', $message, $priceMatch);
        $price = $priceMatch[1] ?? null;
        
        // Extraire la zone
        $zones = ['dakar', 'ngor', 'saly', 'mbour', 'plateau', 'almadie', 'yoff'];
        $foundZone = null;
        foreach ($zones as $zone) {
            if (str_contains($message, $zone)) {
                $foundZone = ucfirst($zone);
                break;
            }
        }
        
        if ($count === 1) {
            if ($price) {
                return "🎉 J'ai trouvé un hôtel à {$price} {$currency} pour vous :";
            }
            if ($foundZone) {
                return "📍 Voici un hôtel situé à {$foundZone} qui pourrait vous plaire :";
            }
            return "🏨 Voici l'hôtel que j'ai trouvé pour vous :";
        }
        
        if ($count <= 3) {
            if ($price && str_contains($message, 'moins')) {
                return "💰 J'ai trouvé {$count} hôtels à moins de {$price} {$currency} :";
            }
            if ($price && str_contains($message, 'plus')) {
                return "💰 J'ai trouvé {$count} hôtels à plus de {$price} {$currency} :";
            }
            if ($foundZone) {
                return "📍 Voici {$count} hôtels situés à {$foundZone} :";
            }
            return "🏨 J'ai trouvé {$count} hôtels qui correspondent à votre recherche :";
        }
        
        return "🏨 J'ai trouvé {$count} hôtels qui pourraient vous intéresser. En voici quelques-uns :";
    }
    
    /**
     * Réponse quand aucun résultat
     */
    private function noResultsResponse(string $message, string $currency): JsonResponse
    {
        preg_match('/(\d+)/', $message, $priceMatch);
        $price = $priceMatch[1] ?? null;
        
        $zones = ['dakar', 'ngor', 'saly', 'mbour', 'plateau', 'almadie', 'yoff'];
        $foundZone = null;
        foreach ($zones as $zone) {
            if (str_contains($message, $zone)) {
                $foundZone = ucfirst($zone);
                break;
            }
        }
        
        if ($price && $foundZone) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Je suis désolé, je n'ai pas trouvé d'hôtel à {$foundZone} avec un budget de {$price} {$currency}. Voulez-vous que je cherche avec un budget différent ou dans un autre quartier ?"
            ]);
        }
        
        if ($price) {
            return response()->json([
                'type' => 'text',
                'reply' => "😕 Désolé, aucun hôtel trouvé à {$price} {$currency}. Quel est votre budget maximum ? Je peux vous proposer des alternatives."
            ]);
        }
        
        if ($foundZone) {
            return response()->json([
                'type' => 'text',
                'reply' => "📍 Je n'ai pas encore d'hôtel à {$foundZone}. Essayez Dakar, Ngor ou Saly ? Je vous trouverai quelque chose de bien !"
            ]);
        }
        
        return response()->json([
            'type' => 'text',
            'reply' => "Je n'ai pas trouvé d'hôtel correspondant. Pouvez-vous me donner plus de détails ? Votre budget, un quartier, ou le nom d'un hôtel que vous cherchez ?"
        ]);
    }
}