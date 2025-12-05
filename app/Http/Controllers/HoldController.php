<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HoldController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'qty' => 'required|integer|min:1',
        ]);

        $productId = $validated['product_id'];
        $qty = $validated['qty'];

        try {
            $result = DB::transaction(function () use ($productId, $qty) {
                // 1. Lock the product to prevent race conditions
                $product = Product::where('id', $productId)->lockForUpdate()->first();

                if (!$product) {
                    throw new \Exception('Product not found.', 404);
                }

                // 2. Calculate Available Stock Dynamically
                // Total Stock - Existing Holds
                // Note: Product lock ensures no concurrent modifications to holds for this product
                $currentHolds = Hold::where('product_id', $productId)
                    ->where('expires_at', '>', now())
                    ->sum('qty');

                $available = $product->stock - $currentHolds;

                // 3. Check availability
                if ($available < $qty) {
                    // Log contention metric
                    Log::info('Hold creation failed: insufficient stock', [
                        'product_id' => $productId,
                        'requested_qty' => $qty,
                        'available_stock' => $available,
                        'metric' => 'stock_contention'
                    ]);
                    throw new \Exception('Insufficient stock.', 409);
                }

                // 4. Create the Hold (Do NOT decrement product stock yet)
                $hold = Hold::create([
                    'product_id' => $productId,
                    'qty' => $qty,
                    'expires_at' => now()->addMinutes(2),
                ]);

                // Invalidate product cache to reflect new availability
                Cache::forget("product_{$productId}");

                // Log successful hold creation
                Log::info('Hold created successfully', [
                    'hold_id' => $hold->id,
                    'product_id' => $productId,
                    'qty' => $qty,
                    'metric' => 'hold_created'
                ]);

                return $hold;
            });

            return response()->json([
                'hold_id' => $result->id,
                'expires_at' => $result->expires_at,
            ], 201);

        } catch (\Exception $e) {
            $status = $e->getCode() ?: 500;
            if ($status < 100 || $status > 599) $status = 400;
            return response()->json(['error' => $e->getMessage()], $status);
        }
    }
}