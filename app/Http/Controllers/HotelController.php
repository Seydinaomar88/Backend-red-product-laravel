<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;  

class HotelController extends Controller
{
    /**
     * LIST HOTELS (seulement ceux de l'utilisateur)
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            Log::info('User ID:', ['id' => $userId]);
            
            $hotels = Hotel::where('user_id', $userId)
                ->latest()
                ->get();
            
            Log::info('Hotels found:', ['count' => $hotels->count()]);
            
            // Transformer les données
            $formattedHotels = $hotels->map(function ($hotel) {
                return [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                    'address' => $hotel->address,
                    'email' => $hotel->email,
                    'phone' => $hotel->telephone ?? '',
                    'pricePerNight' => (float)$hotel->price,
                    'currency' => $hotel->currency ?? 'XOF',
                    'photo' => $hotel->image,
                    'created_at' => $hotel->created_at,
                    'updated_at' => $hotel->updated_at,
                ];
            });
            
            return response()->json([
                'hotels' => $formattedHotels
            ]);
            
        } catch (\Exception $e) {
            Log::error('Hotels error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * CREATE HOTEL
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'address' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:20',        // frontend envoie 'phone'
                'pricePerNight' => 'required|numeric|min:0', // frontend envoie 'pricePerNight'
                'currency' => 'nullable|string|max:10',
                'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048', // frontend envoie 'photo'
            ]);

            $imagePath = null;

            // Upload image
            if ($request->hasFile('photo')) {  // ← 'photo' au lieu de 'image'
                $file = $request->file('photo');
                $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('hotels', $fileName, 'public');
                $imagePath = 'hotels/' . $fileName;
            }

            // Création avec mapping des champs
            $hotel = Hotel::create([
                'name' => $validated['name'],
                'address' => $validated['address'],
                'email' => $validated['email'],
                'telephone' => $validated['phone'] ?? null,           // mapping phone -> telephone
                'price' => $validated['pricePerNight'],               // mapping pricePerNight -> price
                'currency' => $validated['currency'] ?? 'XOF',
                'image' => $imagePath,                                // mapping photo -> image
                'user_id' => $request->user()->id,
            ]);

            // Retourner au format frontend
            return response()->json([
                'message' => 'Hotel created successfully',
                'hotel' => [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                    'address' => $hotel->address,
                    'email' => $hotel->email,
                    'phone' => $hotel->telephone,
                    'pricePerNight' => $hotel->price,
                    'currency' => $hotel->currency,
                    'photo' => $hotel->image,
                    'created_at' => $hotel->created_at,
                    'updated_at' => $hotel->updated_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating hotel',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * SHOW ONE HOTEL
     */
    public function show(Request $request, Hotel $hotel)
    {
        // Protection
        if ($hotel->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Retourner au format frontend
        return response()->json([
            'hotel' => [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'address' => $hotel->address,
                'email' => $hotel->email,
                'phone' => $hotel->telephone,
                'pricePerNight' => $hotel->price,
                'currency' => $hotel->currency,
                'photo' => $hotel->image,
                'created_at' => $hotel->created_at,
                'updated_at' => $hotel->updated_at,
            ]
        ]);
    }

    /**
     * UPDATE HOTEL
     */
    public function update(Request $request, Hotel $hotel)
    {
        try {
            // Protection
            if ($hotel->user_id !== $request->user()->id) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 403);
            }

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'address' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|max:255',
                'phone' => 'sometimes|nullable|string|max:20',
                'pricePerNight' => 'sometimes|required|numeric|min:0',
                'currency' => 'nullable|string|max:10',
                'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            // Update image
            if ($request->hasFile('photo')) {
                if ($hotel->image) {
                    Storage::disk('public')->delete($hotel->image);
                }

                $file = $request->file('photo');
                $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('hotels', $fileName, 'public');
                $hotel->image = 'hotels/' . $fileName;
            }

            // Mise à jour avec mapping
            if (isset($validated['name'])) $hotel->name = $validated['name'];
            if (isset($validated['address'])) $hotel->address = $validated['address'];
            if (isset($validated['email'])) $hotel->email = $validated['email'];
            if (isset($validated['phone'])) $hotel->telephone = $validated['phone'];
            if (isset($validated['pricePerNight'])) $hotel->price = $validated['pricePerNight'];
            if (isset($validated['currency'])) $hotel->currency = $validated['currency'];
            
            $hotel->save();

            return response()->json([
                'message' => 'Hotel updated successfully',
                'hotel' => [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                    'address' => $hotel->address,
                    'email' => $hotel->email,
                    'phone' => $hotel->telephone,
                    'pricePerNight' => $hotel->price,
                    'currency' => $hotel->currency,
                    'photo' => $hotel->image,
                    'created_at' => $hotel->created_at,
                    'updated_at' => $hotel->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating hotel',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE HOTEL
     */
    public function destroy(Request $request, Hotel $hotel)
    {
        try {
            // Protection
            if ($hotel->user_id !== $request->user()->id) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 403);
            }

            if ($hotel->image) {
                Storage::disk('public')->delete($hotel->image);
            }

            $hotel->delete();

            return response()->json([
                'message' => 'Hotel deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting hotel',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}