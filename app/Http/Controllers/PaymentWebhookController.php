<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    /**
     * Handle the incoming payment webhook.
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Validate Input
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'status' => 'required|string|in:success,failed',
            'idempotency_key' => 'required|string',
        ]);

        $orderId = $validated['order_id'];
        $status = $validated['status'];
        $key = $validated['idempotency_key'];

        try {
            DB::transaction(function () use ($orderId, $status, $key) {
                // 2. Lock the Order
                $order = Order::where('id', $orderId)->lockForUpdate()->first();

                if (!$order) {
                    throw new \Exception('Order not found.', 404);
                }

                // 3. Idempotency Check
                // If we already processed this exact key, don't do it again.
                if ($order->payment_idempotency_key === $key) {
                    return; // Return early, do nothing, return 200 OK outside transaction
                }

                // If the order is already in a final state (paid/cancelled) but the key is different,
                // that's weird (maybe a duplicate payment attempt?), but we shouldn't change the state.
                if ($order->status !== 'pending') {
                    return;
                }

                // 4. Update the Key so we know we processed this
                $order->payment_idempotency_key = $key;

                // 5. Handle Status
                if ($status === 'success') {
                    $order->status = 'paid';
                    $order->save();
                } else {
                    // Payment Failed: Cancel order AND return stock
                    $order->status = 'cancelled';
                    $order->save();

                    // Increment product stock
                    $order->product->increment('stock', $order->qty);
                }
            });

            return response()->json(['message' => 'Webhook processed']);

        } catch (\Exception $e) {
            $status = $e->getCode() ?: 500;
            if ($status < 100 || $status > 599) $status = 400;
            return response()->json(['error' => $e->getMessage()], $status);
        }
    }
}