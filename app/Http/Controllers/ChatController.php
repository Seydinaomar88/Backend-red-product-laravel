<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Hotel;

class ChatController extends Controller
{
    private string $apiKey;
    private string $model  = 'grok-3-latest'; // ou grok-2-1212
    private string $apiUrl = 'https://api.x.ai/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.xai.api_key');
    }

    /**
     * Point d'entrée principal du chatbot
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message'           => 'required|string|max:1000',
            'history'           => 'nullable|array',
            'history.*.role'    => 'required_with:history|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
        ]);

        $user     = $request->user();
        $message  = trim($request->input('message'));
        $history  = $request->input('history', []);
        $currency = $user->currency ?? 'FCFA';

        $hotels = Hotel::where('user_id', $user->id)->get();

        $systemPrompt = $this->buildSystemPrompt($hotels, $currency, $user);
        $messages     = $this->buildMessages($systemPrompt, $history, $message);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(30)->post($this->apiUrl, [
                'model'       => $this->model,
                'messages'    => $messages,
                'max_tokens'  => 1024,
                'temperature' => 0.7,
            ]);

            if ($response->failed()) {
                Log::error('Grok API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return $this->fallbackResponse();
            }

            $data  = $response->json();
            $reply = $data['choices'][0]['message']['content'] ?? '';

            return $this->parseAndFormat($reply, $hotels, $currency);

        } catch (\Exception $e) {
            Log::error('ChatController error', ['error' => $e->getMessage()]);
            return $this->fallbackResponse();
        }
    }

    /**
     * Construit le prompt système avec les données hôtels en temps réel
     */
    private function buildSystemPrompt(Collection $hotels, string $currency, object $user): string
    {
        $userName     = $user->name ?? 'cher utilisateur';
        $hotelContext = $this->buildHotelContext($hotels, $currency);

        return <<<PROMPT
Tu es un assistant hôtelier expert et professionnel pour la plateforme Red Product.
Tu aides {$userName} à gérer et explorer son catalogue d'hôtels.

VOICI LES DONNÉES EN TEMPS RÉEL :
{$hotelContext}

MONNAIE PAR DÉFAUT : {$currency}

TES CAPACITÉS :
1. Répondre à des questions sur les hôtels (prix, adresse, contact, description)
2. Faire des comparaisons (moins cher, plus cher, entre deux prix, par zone)
3. Filtrer par budget, quartier, nombre d'étoiles
4. Donner des recommandations personnalisées selon les besoins du client
5. Analyser les tendances du catalogue (prix moyen, répartition par zone, etc.)

RÈGLES DE RÉPONSE :
- Sois TOUJOURS chaleureux, professionnel et précis
- Utilise des emojis pertinents pour rendre les réponses agréables
- Formate bien les prix avec des séparateurs de milliers
- Si tu ne trouves pas d'hôtel correspondant, propose des alternatives proches
- Pour les questions hors sujet hôtelier, recentre poliment la conversation
- Réponds TOUJOURS en français sauf si l'utilisateur écrit dans une autre langue
- Si on te demande plusieurs hôtels, liste-les clairement avec des puces
- Pour les contacts, donne toujours téléphone ET email quand disponibles

FORMAT SPÉCIAL :
Quand tu dois lister des hôtels à afficher en cartes visuelles, termine ta réponse par :
```hotels
[{"id": 1, "name": "Nom", "price": "25 000", "address": "...", "phone": "...", "email": "..."}]
```
N'utilise ce bloc JSON QUE pour les listes d'hôtels cliquables.
Pour les réponses textuelles simples (stats, contacts uniques, conseils), ne mets PAS de JSON.
PROMPT;
    }

    /**
     * Construit le contexte hôtels injecté dans le prompt
     */
    private function buildHotelContext(Collection $hotels, string $currency): string
    {
        if ($hotels->isEmpty()) {
            return "L'utilisateur n'a aucun hôtel dans son catalogue pour le moment.";
        }

        $lines   = [];
        $lines[] = "CATALOGUE D'HÔTELS (total : {$hotels->count()})";
        $lines[] = str_repeat('-', 50);

        foreach ($hotels as $hotel) {
            $lines[] = "• ID #{$hotel->id} | {$hotel->name}";
            $lines[] = "  Adresse    : {$hotel->address}";
            $lines[] = "  Prix/nuit  : " . number_format($hotel->price, 0, ',', ' ') . " {$currency}";
            $lines[] = "  Téléphone  : " . ($hotel->phone ?? 'Non renseigné');
            $lines[] = "  Email      : " . ($hotel->email ?? 'Non renseigné');
            $lines[] = "  Étoiles    : " . ($hotel->stars ?? 'N/A');
            $lines[] = "  Description: " . ($hotel->description ?? 'Aucune');
            $lines[] = "";
        }

        $lines[] = str_repeat('-', 50);
        $lines[] = "Prix minimum : " . number_format($hotels->min('price'), 0, ',', ' ') . " {$currency}";
        $lines[] = "Prix maximum : " . number_format($hotels->max('price'), 0, ',', ' ') . " {$currency}";
        $lines[] = "Prix moyen   : " . number_format(round($hotels->avg('price')), 0, ',', ' ') . " {$currency}";

        return implode("\n", $lines);
    }

    /**
     * Construit le tableau messages au format OpenAI/Grok (system + historique + user)
     */
    private function buildMessages(string $systemPrompt, array $history, string $newMessage): array
    {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach (array_slice($history, -20) as $entry) {
            if (!empty($entry['role']) && !empty($entry['content'])) {
                $messages[] = ['role' => $entry['role'], 'content' => $entry['content']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $newMessage];

        return $messages;
    }

    /**
     * Parse la réponse Grok et détecte les blocs JSON hôtels
     */
    private function parseAndFormat(string $reply, Collection $hotels, string $currency): JsonResponse
    {
        if (preg_match('/```hotels\s*([\s\S]*?)```/i', $reply, $matches)) {
            $jsonStr  = trim($matches[1]);
            $textPart = trim(preg_replace('/```hotels[\s\S]*?```/i', '', $reply));

            try {
                $hotelData = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);

                $enriched = [];
                foreach ($hotelData as $item) {
                    $dbHotel = $hotels->firstWhere('id', $item['id'] ?? null);
                    if ($dbHotel) {
                        $enriched[] = [
                            'id'       => $dbHotel->id,
                            'name'     => $dbHotel->name,
                            'address'  => $dbHotel->address,
                            'price'    => number_format($dbHotel->price, 0, ',', ' '),
                            'currency' => $currency,
                            'image'    => $dbHotel->image,
                            'phone'    => $dbHotel->phone ?? 'Non renseigné',
                            'email'    => $dbHotel->email ?? 'Non renseigné',
                            'stars'    => $dbHotel->stars ?? null,
                        ];
                    }
                }

                if (!empty($enriched)) {
                    return response()->json([
                        'type'    => 'hotels',
                        'message' => $textPart,
                        'count'   => count($enriched),
                        'data'    => $enriched,
                    ]);
                }

            } catch (\JsonException $e) {
                Log::warning('Failed to parse hotels JSON from Grok response', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['type' => 'text', 'reply' => $reply]);
    }

    /**
     * Réponse de secours en cas d'erreur API
     */
    private function fallbackResponse(): JsonResponse
    {
        return response()->json([
            'type'  => 'text',
            'reply' => "⚠️ **Service temporairement indisponible**\n\nJe n'arrive pas à joindre Grok en ce moment.\n\n💡 Réessayez dans quelques instants.",
        ], 503);
    }
}