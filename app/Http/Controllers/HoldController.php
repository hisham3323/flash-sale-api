<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HoldController extends Controller
{
    /**
     * Create a temporary hold on stock.
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Validate the input
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'qty' => 'required|integer|min:1',
        ]);

        $productId = $validated['product_id'];
        $qty = $validated['qty'];

        try {
            // 2. Start a Database Transaction
            // This ensures all the following steps happen together or not at all.
            $result = DB::transaction(function () use ($productId, $qty) {
                
                // 3. Lock the product row for update. 
                // This prevents other requests from reading/modifying this product 
                // until this transaction is finished.
                $product = Product::where('id', $productId)->lockForUpdate()->first();

                if (!$product) {
                    throw new \Exception('Product not found.', 404);
                }

                // 4. Check stock availability
                if ($product->stock < $qty) {
                    throw new \Exception('Insufficient stock.', 409); // 409 Conflict
                }

                // 5. Decrement stock
                $product->stock -= $qty;
                $product->save();

                // 6. Create the Hold record (expires in 2 minutes)
                $hold = Hold::create([
                    'product_id' => $productId,
                    'qty' => $qty,
                    'expires_at' => now()->addMinutes(2),
                ]);

                return $hold;
            });

            // 7. Return success
            return response()->json([
                'hold_id' => $result->id,
                'expires_at' => $result->expires_at,
            ], 201);

        } catch (\Exception $e) {
            // Handle errors (stock issues or product not found)
            $status = $e->getCode() ?: 500;
            // Ensure status is a valid HTTP status code
            if ($status < 100 || $status > 599) {
                $status = 400;
            }
            
            return response()->json(['error' => $e->getMessage()], $status);
        }
    }
}