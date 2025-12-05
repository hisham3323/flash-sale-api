<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Hold;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    /**
     * Display the specified product with caching.
     */
    public function show(string $id): JsonResponse
    {
        // Requirement #2: Caching
        // We cache the result for 5 seconds. This drastically reduces DB load during 
        // a flash sale burst, while ensuring stock updates appear quickly.
        $data = Cache::remember("product_{$id}", 5, function () use ($id) {
            
            $product = Product::findOrFail($id);

            // Requirement #1: Accurate available stock
            // Logic: Total Physical Stock (DB) - Sum of Active Holds
            $reservedQty = Hold::where('product_id', $id)
                ->where('expires_at', '>', now())
                ->sum('qty');

            // Ensure we don't show negative numbers
            $available = max(0, $product->stock - $reservedQty);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'stock' => $available,
            ];
        });

        return response()->json($data);
    }
}