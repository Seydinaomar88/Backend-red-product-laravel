<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HotelController extends Controller
{
    /**
     * LIST HOTELS (seulement ceux de l'utilisateur)
     */
    public function index(Request $request)
    {
        $hotels = Hotel::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'hotels' => $hotels
        ]);
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
                'telephone',
                'price' => 'required|numeric|min:0',
                'currency' => 'nullable|string|max:10',
                'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            $imagePath = null;

            // Upload image
            if ($request->hasFile('image')) {

                $file = $request->file('image');

                $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->storeAs('hotels', $fileName, 'public');

                $imagePath = 'hotels/' . $fileName;
            }

            // Création
            $hotel = Hotel::create([
                'name' => $validated['name'],
                'address' => $validated['address'],
                'email' => $validated['email'],
                'telephone' => $validated['telephone'] ?? null,
                'price' => $validated['price'],
                'currency' => $validated['currency'] ?? 'XOF',
                'image' => $imagePath,
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Hotel created successfully',
                'hotel' => $hotel
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

        return response()->json([
            'hotel' => $hotel
        ]);
    }

    /**
     * UPDATE HOTEL
     */
    public function update(Request $request, Hotel $hotel)
    {
        try {

            //  Protection
            if ($hotel->user_id !== $request->user()->id) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 403);
            }

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'address' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|max:255',
                'telephone' => 'sometimes|nullable|string|max:20',
                'price' => 'sometimes|required|numeric|min:0',
                'currency' => 'nullable|string|max:10',
                'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            // Update image
            if ($request->hasFile('image')) {

                if ($hotel->image) {
                    Storage::disk('public')->delete($hotel->image);
                }

                $file = $request->file('image');

                $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->storeAs('hotels', $fileName, 'public');

                $validated['image'] = 'hotels/' . $fileName;
            }

            $hotel->update($validated);

            return response()->json([
                'message' => 'Hotel updated successfully',
                'hotel' => $hotel->fresh()
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