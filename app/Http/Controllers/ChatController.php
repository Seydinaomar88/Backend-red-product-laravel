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
     * Point d'entrée principal du chat
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
        
        // Détection et application de la recherche
        $result = $this->handleSearch($message, $query, $userCurrency);
        
        return $result;
    }
    
    /**
     * Gère la recherche selon le type détecté
     */
    private function handleSearch(string $message, Builder $query, string $userCurrency): JsonResponse
    {
        // Détection du type de recherche
        $searchType = $this->detectSearchType($message);
        
        // Gestion des salutations
        if ($searchType === 'greeting') {
            return $this->greetingResponse();
        }
        
        // Gestion de l'aide
        if ($searchType === 'help') {
            return $this->helpResponse();
        }
        
        // Application des filtres selon le type
        $hasFilter = $this->applyFilters($searchType, $message, $query);
        
        // Si aucun filtre valide
        if (!$hasFilter) {
            return $this->helpResponse();
        }
        
        // Exécution de la recherche
        /** @var Collection $hotels */
        $hotels = $query->limit(10)->get();
        
        // Retourner les résultats formatés
        return $this->formatResponse($hotels, $searchType, $message, $userCurrency);
    }
    
    /**
     * Détecte le type de recherche à partir du message
     */
    private function detectSearchType(string $message): string
    {
        // Salutations
        $greetings = ['bonjour', 'salut', 'coucou', 'hello', 'hi', 'hey'];
        foreach ($greetings as $greeting) {
            if (str_contains($message, $greeting)) {
                return 'greeting';
            }
        }
        
        // Aide
        if ($message === 'aide' || $message === 'help' || $message === '?') {
            return 'help';
        }
        
        // Recherche par NOM
        if (str_contains($message, 'nom') || 
            str_contains($message, 'appelle') || 
            (str_contains($message, 'cherche') && str_contains($message, 'hotel'))) {
            return 'name';
        }
        
        // Recherche par CONTACT (téléphone ou email)
        if (str_contains($message, 'contact') || 
            str_contains($message, 'telephone') || 
            str_contains($message, 'tel') || 
            str_contains($message, 'phone') ||
            str_contains($message, 'email') ||
            str_contains($message, '@')) {
            return 'contact';
        }
        
        // Recherche par ADRESSE
        if (str_contains($message, 'adresse') || 
            str_contains($message, 'rue') || 
            str_contains($message, 'quartier') ||
            str_contains($message, 'situé') ||
            str_contains($message, 'localisation')) {
            return 'address';
        }
        
        // Recherche par PRIX
        if (str_contains($message, 'prix') || preg_match('/\d+/', $message)) {
            
            // Entre deux prix
            if (str_contains($message, 'entre') && str_contains($message, 'et')) {
                return 'price_range';
            }
            
            // Moins de / inférieur
            if (str_contains($message, 'moins') || 
                str_contains($message, 'inférieur') || 
                str_contains($message, '<')) {
                return 'max_price';
            }
            
            // Plus de / supérieur
            if (str_contains($message, 'plus') || 
                str_contains($message, 'supérieur') || 
                str_contains($message, '>')) {
                return 'min_price';
            }
            
            // Prix exact
            if (str_contains($message, 'à ') || 
                str_contains($message, 'exact') || 
                str_contains($message, '=')) {
                return 'exact_price';
            }
            
            // Juste un chiffre
            if (preg_match('/^\d+$/', trim($message))) {
                return 'exact_price';
            }
        }
        
        // Liste tous les hôtels
        if (str_contains($message, 'tous') || 
            str_contains($message, 'liste') || 
            str_contains($message, 'tout')) {
            return 'all_hotels';
        }
        
        // Recherche par ville/zone (fallback)
        $zones = ['dakar', 'ngor', 'saly', 'mbour', 'plateau', 'almadie', 'yoff', 'ouakam'];
        foreach ($zones as $zone) {
            if (str_contains($message, $zone)) {
                return 'address';
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Applique les filtres selon le type de recherche
     */
    private function applyFilters(string $searchType, string $message, Builder $query): bool
    {
        switch ($searchType) {
            case 'name':
                $hotelName = $this->extractHotelName($message);
                if (!empty($hotelName)) {
                    $query->where('name', 'like', "%{$hotelName}%");
                    return true;
                }
                break;
                
            case 'exact_price':
                $price = $this->extractPrice($message);
                if ($price > 0) {
                    $query->where('price', $price);
                    return true;
                }
                break;
                
            case 'max_price':
                $price = $this->extractPrice($message);
                if ($price > 0) {
                    $query->where('price', '<=', $price);
                    return true;
                }
                break;
                
            case 'min_price':
                $price = $this->extractPrice($message);
                if ($price > 0) {
                    $query->where('price', '>=', $price);
                    return true;
                }
                break;
                
            case 'price_range':
                $prices = $this->extractPriceRange($message);
                if ($prices['min'] > 0 && $prices['max'] > 0) {
                    $query->whereBetween('price', [$prices['min'], $prices['max']]);
                    return true;
                }
                break;
                
            case 'address':
                $address = $this->extractAddress($message);
                if (!empty($address)) {
                    $query->where('address', 'like', "%{$address}%");
                    return true;
                }
                break;
                
            case 'contact':
                $contact = $this->extractContact($message);
                if (!empty($contact)) {
                    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                        $query->where('email', $contact);
                    } else {
                        $query->where('phone', 'like', "%{$contact}%");
                    }
                    return true;
                }
                break;
                
            case 'all_hotels':
                // Pas de filtre, on retourne tous les hôtels
                return true;
                
            default:
                return false;
        }
        
        return false;
    }
    
    /**
     * Extrait le nom de l'hôtel du message
     */
    private function extractHotelName(string $message): string
    {
        $keywords = ['cherche', 'hotel', 'nomme', 'appelle', 'nom', 'trouve'];
        $name = $message;
        foreach ($keywords as $keyword) {
            $name = str_replace($keyword, '', $name);
        }
        return trim($name);
    }
    
    /**
     * Extrait le prix du message
     */
    private function extractPrice(string $message): int
    {
        preg_match('/(\d+)/', $message, $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }
    
    /**
     * Extrait la fourchette de prix
     */
    private function extractPriceRange(string $message): array
    {
        preg_match_all('/(\d+)/', $message, $matches);
        return [
            'min' => isset($matches[0][0]) ? (int)$matches[0][0] : 0,
            'max' => isset($matches[0][1]) ? (int)$matches[0][1] : 0
        ];
    }
    
    /**
     * Extrait l'adresse du message
     */
    private function extractAddress(string $message): string
    {
        $keywords = ['adresse', 'situé', 'située', 'localisation', 'rue', 'quartier', 'ville', 'zone', 'à'];
        $address = $message;
        foreach ($keywords as $keyword) {
            $address = str_replace($keyword, '', $address);
        }
        return trim($address);
    }
    
    /**
     * Extrait le contact (email ou téléphone)
     */
    private function extractContact(string $message): string
    {
        // Chercher un email
        preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $message, $emailMatch);
        if (!empty($emailMatch)) {
            return $emailMatch[0];
        }
        
        // Chercher un téléphone (9 à 15 chiffres)
        preg_match('/(\d{9,15})/', $message, $phoneMatch);
        if (!empty($phoneMatch)) {
            return $phoneMatch[0];
        }
        
        return '';
    }
    
    /**
     * Réponse de salutation
     */
    private function greetingResponse(): JsonResponse
    {
        return response()->json([
            'type' => 'text',
            'reply' => " Bonjour ! Je suis votre assistant hôtelier professionnel.\n\n" .
                       "Je peux rechercher des hôtels par :\n" .
                       "•**Nom** : \"cherche hôtel Rade\"\n" .
                       "•**Prix exact** : \"hôtel à 25000\"\n" .
                       "• **Prix max** : \"moins de 30000\"\n" .
                       "•**Prix min** : \"plus de 50000\"\n" .
                       "•**Fourchette** : \"entre 20000 et 50000\"\n" .
                       "•**Adresse** : \"hôtel à Dakar\"\n" .
                       "•**Contact** : \"cherche 771234567\" ou \"contact@hotel.com\"\n\n" .
                       "Tapez **aide** pour plus d'exemples"
        ]);
    }
    
    /**
     * Réponse d'aide
     */
    private function helpResponse(): JsonResponse
    {
        return response()->json([
            'type' => 'help',
            'reply' => "**Guide d'utilisation du chat**\n\n" .
                       "**Par NOM :**\n" .
                       "• \"cherche hôtel Rade\"\n" .
                       "• \"hôtel qui s'appelle Terrou\"\n\n" .
                       
                       "**Par PRIX EXACT :**\n" .
                       "• \"hôtel à 25000\"\n" .
                       "• \"prix exact 15000\"\n\n" .
                       
                       "**Par PRIX MAX :**\n" .
                       "• \"moins de 30000\"\n" .
                       "• \"hôtel à moins de 20000\"\n\n" .
                       
                       "**Par PRIX MIN :**\n" .
                       "• \"plus de 50000\"\n" .
                       "• \"hôtel supérieur à 100000\"\n\n" .
                       
                       "**Par FOURCHETTE :**\n" .
                       "• \"entre 20000 et 50000\"\n\n" .
                       
                       "**Par ADRESSE :**\n" .
                       "• \"hôtel à Dakar Plateau\"\n" .
                       "• \"quartier Ngor\"\n\n" .
                       
                       "**Par CONTACT :**\n" .
                       "• \"téléphone 771234567\"\n" .
                       "• \"email contact@hotel.com\"\n\n" .
                       
                       "**Voir TOUS :**\n" .
                       "• \"liste tous les hôtels\"\n" .
                       "• \"affiche tout\"\n"
        ]);
    }
    
    /**
     * Formate la réponse selon les résultats
     */
    private function formatResponse(Collection $hotels, string $searchType, string $message, string $currency): JsonResponse
    {
        if ($hotels->isEmpty()) {
            return $this->emptyResponse($searchType, $message, $currency);
        }
        
        $title = $this->getResultTitle($searchType, $hotels->count(), $message, $currency);
        
        return response()->json([
            'type' => 'hotels',
            'count' => $hotels->count(),
            'message' => $title,
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
     * Titre du résultat selon le type de recherche
     */
    private function getResultTitle(string $searchType, int $count, string $message, string $currency): string
    {
        $price = $this->extractPrice($message);
        
        switch ($searchType) {
            case 'name':
                $name = $this->extractHotelName($message);
                if ($name === '') {
                    return "{$count} hôtel(s) trouvé(s) :";
                }
                return "{$count} hôtel(s) trouvé(s) pour \"{$name}\" :";
                
            case 'exact_price':
                if ($count === 1) {
                    return "Hôtel à {$price} {$currency} trouvé :";
                }
                return "{$count} hôtels à {$price} {$currency} trouvés :";
                
            case 'max_price':
                return "Hôtels à moins de {$price} {$currency} ({$count}) :";
                
            case 'min_price':
                return "Hôtels à plus de {$price} {$currency} ({$count}) :";
                
            case 'price_range':
                $prices = $this->extractPriceRange($message);
                return "Hôtels entre {$prices['min']} et {$prices['max']} {$currency} ({$count}) :";
                
            case 'address':
                $address = $this->extractAddress($message);
                if ($address === '') {
                    return "Hôtels trouvés ({$count}) :";
                }
                return "Hôtels à {$address} ({$count}) :";
                
            case 'contact':
                return "Hôtel(s) trouvé(s) ({$count}) :";
                
            default:
                return "Liste des hôtels ({$count}) :";
        }
    }
    
    /**
     * Réponse quand aucun hôtel trouvé
     */
    private function emptyResponse(string $searchType, string $message, string $currency): JsonResponse
    {
        $price = $this->extractPrice($message);
        $suggestions = "";
        
        switch ($searchType) {
            case 'name':
                $name = $this->extractHotelName($message);
                $suggestions = "Aucun hôtel nommé \"{$name}\" n'a été trouvé.\n";
                $suggestions .= "Essayez avec un autre nom ou tapez \"liste tous les hôtels\"";
                break;
                
            case 'exact_price':
                $suggestions = "Aucun hôtel disponible à exactement {$price} {$currency}.\n";
                $suggestions .= "Suggestions :\n";
                $suggestions .= "• \"moins de {$price}\"\n";
                $suggestions .= "• \"plus de {$price}\"\n";
                $suggestions .= "• \"entre " . max(0, $price - 5000) . " et " . ($price + 5000) . "\"";
                break;
                
            case 'max_price':
                $suggestions = "Aucun hôtel trouvé à moins de {$price} {$currency}.\n";
                $suggestions .= "Essayez d'augmenter votre budget à " . ($price + 10000) . " {$currency}";
                break;
                
            case 'min_price':
                $suggestions = " Aucun hôtel trouvé à plus de {$price} {$currency}.\n";
                $suggestions .= "Essayez de diminuer votre budget ou tapez \"tous les hôtels\"";
                break;
                
            case 'address':
                $address = $this->extractAddress($message);
                $suggestions = "Aucun hôtel trouvé à \"{$address}\".\n";
                $suggestions .= "Essayez : Dakar, Ngor, Saly, Mbour, Plateau";
                break;
                
            case 'contact':
                $suggestions = "Aucun hôtel trouvé avec ce contact.\n";
                $suggestions .= "Vérifiez le numéro ou l'email";
                break;
                
            default:
                $suggestions = "Aucun hôtel trouvé.\n";
                $suggestions .= "Tapez **aide** pour voir comment m'utiliser";
        }
        
        return response()->json([
            'type' => 'empty',
            'reply' => "Aucun résultat.\n\n{$suggestions}"
        ]);
    }
}