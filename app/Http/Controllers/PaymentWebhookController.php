<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            DB::transaction(function () use ($orderId, $status, $key, $request) {
                
                // 2. Check if this specific webhook key was already processed
                if (WebhookLog::where('idempotency_key', $key)->exists()) {
                    return; // Already handled, do nothing, return 200
                }

                // 3. Lock the Order FIRST
                // We must find the order before creating the log, otherwise the 
                // Foreign Key constraint on webhook_logs will fail and throw a 500/400 error.
                $order = Order::where('id', $orderId)->lockForUpdate()->first();

                if (!$order) {
                    // If order doesn't exist yet, we throw 404 so provider retries later.
                    throw new \Exception('Order not found.', 404);
                }

                // 4. Log the incoming webhook (Now safe because we know Order exists)
                WebhookLog::create([
                    'order_id' => $orderId,
                    'idempotency_key' => $key,
                    'status' => $status,
                    'payload' => $request->all(),
                ]);

                // 5. Check Order State (Out-of-Order Protection)
                if ($order->status !== 'pending') {
                    Log::info("Order {$orderId} is already {$order->status}. Ignoring webhook {$key}.");
                    return;
                }

                // 6. Handle Status Change
                if ($status === 'success') {
                    $order->status = 'paid';
                    $order->save();
                    Log::info("Order {$orderId} marked as paid via webhook {$key}.");
                } else {
                    // Payment Failed: Cancel order AND return stock
                    $order->status = 'cancelled';
                    $order->save();

                    // Return stock
                    $order->product->increment('stock', $order->qty);
                    Log::info("Order {$orderId} cancelled via webhook {$key}. Stock returned.");
                }
            });

            return response()->json(['message' => 'Webhook processed']);

        } catch (\Exception $e) {
            // Keep 404 for "Order not found", otherwise default to 400 for bad requests
            $status = $e->getCode();
            
            // Validate that the status code is a valid HTTP error code
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = 400;
            }
            
            Log::error("Webhook Error: " . $e->getMessage());
            
            return response()->json(['error' => $e->getMessage()], $status);
        }
    }
}