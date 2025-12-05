<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
                    // Log webhook deduplication metric
                    Log::info('Webhook duplicate detected and ignored', [
                        'order_id' => $orderId,
                        'idempotency_key' => $key,
                        'metric' => 'webhook_dedupe'
                    ]);
                    return; // Already handled, do nothing, return 200
                }

                // 3. Lock the Order FIRST
                // We must find the order before creating the log, otherwise the 
                // Foreign Key constraint on webhook_logs will fail and throw a 500/400 error.
                $order = Order::where('id', $orderId)->lockForUpdate()->first();

                if (!$order) {
                    // If order doesn't exist yet, we throw 404 so provider retries later.
                    // Log retry metric
                    Log::info('Webhook received before order creation', [
                        'order_id' => $orderId,
                        'idempotency_key' => $key,
                        'metric' => 'webhook_retry_required'
                    ]);
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
                    Log::info('Webhook ignored: order already processed', [
                        'order_id' => $orderId,
                        'current_status' => $order->status,
                        'webhook_status' => $status,
                        'idempotency_key' => $key,
                        'metric' => 'webhook_out_of_order'
                    ]);
                    return;
                }

                // 6. Handle Status Change
                if ($status === 'success') {
                    $order->status = 'paid';
                    $order->save();
                    Log::info('Order payment successful', [
                        'order_id' => $orderId,
                        'idempotency_key' => $key,
                        'metric' => 'payment_success'
                    ]);
                } else {
                    // Payment Failed: Cancel order AND return stock
                    $order->status = 'cancelled';
                    $order->save();

                    // Return stock
                    $order->product->increment('stock', $order->qty);
                    
                    // Invalidate product cache since stock changed
                    Cache::forget("product_{$order->product_id}");
                    
                    Log::info('Order payment failed, stock returned', [
                        'order_id' => $orderId,
                        'idempotency_key' => $key,
                        'qty_returned' => $order->qty,
                        'metric' => 'payment_failed'
                    ]);
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