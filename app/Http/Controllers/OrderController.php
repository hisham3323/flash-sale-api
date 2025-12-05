<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{
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
                    // Just delete the hold, no stock to return (since we didn't take it yet)
                    $hold->delete();
                    throw new \Exception('Hold has expired.', 400);
                }

                // 3. DEDUCT STOCK PERMANENTLY
                // Since Hold logic changed to "reservation", the Order now claims the physical stock.
                $hold->product->decrement('stock', $hold->qty);

                // Invalidate product cache since stock changed
                Cache::forget("product_{$hold->product_id}");

                // 4. Create the Order
                $totalAmount = $hold->product->price * $hold->qty;

                $order = Order::create([
                    'product_id' => $hold->product_id,
                    'qty' => $hold->qty,
                    'amount' => $totalAmount,
                    'status' => 'pending',
                ]);

                // 5. Delete the Hold (Reservation is converted to Order)
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