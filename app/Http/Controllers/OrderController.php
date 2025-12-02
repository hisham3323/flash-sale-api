<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Convert a Hold into an Order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hold_id' => 'required|integer',
        ]);

        $holdId = $validated['hold_id'];

        try {
            $order = DB::transaction(function () use ($holdId) {
                // 1. Find and Lock the Hold
                $hold = Hold::where('id', $holdId)->lockForUpdate()->first();

                if (!$hold) {
                    throw new \Exception('Hold not found or already used.', 404);
                }

                // 2. Check if expired
                if ($hold->expires_at < now()) {
                    // Ideally, the background job cleans this up, but if we catch it here:
                    // We must release the stock immediately and fail the order.
                    $hold->product->increment('stock', $hold->qty);
                    $hold->delete();
                    throw new \Exception('Hold has expired.', 400);
                }

                // 3. Create the Order
                // Calculate total amount (Price * Qty)
                $totalAmount = $hold->product->price * $hold->qty;

                $order = Order::create([
                    'product_id' => $hold->product_id,
                    'qty' => $hold->qty,
                    'amount' => $totalAmount,
                    'status' => 'pending',
                ]);

                // 4. Delete the Hold
                // The stock is already deducted (during Hold creation), so we don't change stock here.
                // The Order now represents the claim on that stock.
                $hold->delete();

                return $order;
            });

            return response()->json($order, 201);

        } catch (\Exception $e) {
            $status = $e->getCode() ?: 500;
            if ($status < 100 || $status > 599) $status = 400;
            return response()->json(['error' => $e->getMessage()], $status);
        }
    }
}