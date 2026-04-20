<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Cloudinary\Cloudinary;

class HotelController extends Controller
{
    /**
     * LIST HOTELS
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            Log::info('User ID:', ['id' => $userId]);
            
            $hotels = Hotel::where('user_id', $userId)
                ->latest()
                ->get();
            
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
            
            return response()->json(['hotels' => $formattedHotels]);
            
        } catch (\Exception $e) {
            Log::error('Hotels error: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * CREATE HOTEL (avec Cloudinary)
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'address' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:20',
                'pricePerNight' => 'required|numeric|min:0',
                'currency' => 'nullable|string|max:10',
                'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            $imageUrl = null;

            // Upload vers Cloudinary
            if ($request->hasFile('photo')) {
                $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));
                $upload = $cloudinary->uploadApi()->upload($request->file('photo')->getRealPath(), [
                    'folder' => 'hotels'
                ]);
                $imageUrl = $upload['secure_url'];
            }

            $hotel = Hotel::create([
                'name' => $validated['name'],
                'address' => $validated['address'],
                'email' => $validated['email'],
                'telephone' => $validated['phone'] ?? null,
                'price' => $validated['pricePerNight'],
                'currency' => $validated['currency'] ?? 'XOF',
                'image' => $imageUrl,
                'user_id' => $request->user()->id,
            ]);

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
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Store error: ' . $e->getMessage());
            return response()->json(['message' => 'Error creating hotel: ' . $e->getMessage()], 500);
        }
    }

    /**
     * SHOW ONE HOTEL
     */
    public function show(Request $request, Hotel $hotel)
    {
        if ($hotel->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
            ]
        ]);
    }

    /**
     * UPDATE HOTEL
     */
    public function update(Request $request, Hotel $hotel)
    {
        try {
            if ($hotel->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
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

            if ($request->hasFile('photo')) {
                $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));
                $upload = $cloudinary->uploadApi()->upload($request->file('photo')->getRealPath(), [
                    'folder' => 'hotels'
                ]);
                $hotel->image = $upload['secure_url'];
            }

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
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Update error: ' . $e->getMessage());
            return response()->json(['message' => 'Error updating hotel: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE HOTEL
     */
    public function destroy(Request $request, Hotel $hotel)
    {
        try {
            if ($hotel->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $hotel->delete();

            return response()->json(['message' => 'Hotel deleted successfully']);

        } catch (\Exception $e) {
            Log::error('Delete error: ' . $e->getMessage());
            return response()->json(['message' => 'Error deleting hotel: ' . $e->getMessage()], 500);
        }
    }
}