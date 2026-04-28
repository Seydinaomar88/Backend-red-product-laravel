<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Hotel;

class ChatController extends Controller
{
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'city' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $message = strtolower($request->message);
        $userId = $request->user()->id;

        $query = Hotel::where('user_id', $userId);

        /*1. SALUTATION*/
        if (str_contains($message, 'bonjour') || str_contains($message, 'salut')) {
            return response()->json([
                'type' => 'text',
                'reply' => "Bonjour 👋 Je peux vous aider à trouver des hôtels par prix, ville ou localisation."
            ]);
        }

        /*2. PRIX */
        preg_match('/(\d+)/', $message, $matches);
        $price = $matches[1] ?? null;

        if ($price) {

            if (str_contains($message, 'moins')) {
                $query->where('price', '<=', $price);
            }

            if (str_contains($message, 'plus')) {
                $query->where('price', '>=', $price);
            }

            /* 2.5. RECHERCHE GÉNÉRIQUE "hôtels à ..." */
            if (str_contains($message, 'hôtel') || str_contains($message, 'hotel')) {
                // Si aucun filtre spécifique, retourne les 5 premiers
                if ($query->getQuery()->wheres === []) {
                    // Pas de condition = recherche libre
                }
            }

            if (str_contains($message, 'entre')) {
                $parts = explode('et', $message);

                if (count($parts) == 2) {
                    preg_match_all('/(\d+)/', $message, $numbers);

                    if (count($numbers[0]) >= 2) {
                        $query->whereBetween('price', [$numbers[0][0], $numbers[0][1]]);
                    }
                }
            }
        }


        /*3. VILLES / ZONES */
        $zones = [
            'dakar',
            'ngor',
            'almadie',
            'plateau',
            'mbour',
            'saly',
            'lac rose',
            'sine saloum'
        ];

        foreach ($zones as $zone) {
            if (str_contains($message, $zone)) {
                $query->where('address', 'like', "%$zone%");
            }
        }

        /*4. GPS (FUTUR MAP)*/
        if ($request->latitude && $request->longitude) {
            // future: calcul distance (Haversine)
            // pour l'instant simple filtre
        }

        /*5. RESULTAT FINAL*/
        $hotels = $query->limit(10)->get();

        if ($hotels->isEmpty()) {
            return response()->json([
                'type' => 'empty',
                'reply' => "Aucun hôtel trouvé pour votre recherche."
            ]);
        }

        return response()->json([
            'type' => 'hotels',
            'count' => $hotels->count(),
            'data' => $hotels->map(function ($hotel) {
                return [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                    'address' => $hotel->address,
                    'price' => $hotel->price,
                    'currency' => $hotel->currency,
                    'image' => $hotel->image,
                ];
            })
        ]);
    }
}
