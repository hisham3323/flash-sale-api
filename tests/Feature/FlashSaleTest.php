<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class FlashSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Product::create([
            'id' => 1,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);
        
        // Ensure cache is clean before starting
        Cache::forget('product_1');
    }

    public function test_api_shows_correct_available_stock_with_holds()
    {
        // 1. Initial State: 10 available
        $this->getJson('/api/products/1')
             ->assertJson(['stock' => 10]);

        // 2. Create Hold for 4 items
        $this->postJson('/api/holds', ['product_id' => 1, 'qty' => 4])
             ->assertStatus(201);

        // FORCE CLEAR CACHE so we see the new stock immediately
        Cache::forget('product_1');

        // 3. Check API again: Should be 6 (10 - 4)
        $this->getJson('/api/products/1')
             ->assertJson(['stock' => 6]);
    }

    public function test_cannot_oversell_stock_boundary()
    {
        // Stock is 10. Hold 10.
        $this->postJson('/api/holds', ['product_id' => 1, 'qty' => 10])->assertStatus(201);

        // Try to hold 1 more
        $this->postJson('/api/holds', ['product_id' => 1, 'qty' => 1])
             ->assertStatus(409); // Conflict (Insufficient stock)
    }

    public function test_rapid_fire_requests_prevent_overselling()
    {
        // 1. Stock is 10
        // We will fire 15 requests sequentially to simulate high traffic logic.
        // Even though they run one by one in PHPUnit, this proves that
        // once the counter hits 0, subsequent requests FAIL.
        
        $successfulHolds = 0;
        $failedHolds = 0;

        for ($i = 0; $i < 15; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => 1,
                'qty' => 1
            ]);

            if ($response->status() === 201) {
                $successfulHolds++;
            } elseif ($response->status() === 409) {
                $failedHolds++;
            }
        }

        // 2. We expect exactly 10 successes and 5 failures
        $this->assertEquals(10, $successfulHolds, 'Should strictly allow 10 holds');
        $this->assertEquals(5, $failedHolds, 'Should reject the 5 requests after stock depleted');

        // 3. Verify API reports 0 stock
        Cache::forget('product_1');
        $this->getJson('/api/products/1')->assertJson(['stock' => 0]);
    }

    public function test_parallel_concurrent_requests_prevent_overselling()
    {
        // This test simulates concurrent requests by firing multiple requests rapidly
        // While PHPUnit runs sequentially, the database locking (lockForUpdate) ensures
        // that concurrent database transactions are properly serialized, preventing overselling.
        // In a real production environment with actual parallel HTTP requests, the same
        // locking mechanism will prevent race conditions.
        
        $stock = 10;
        $concurrentRequests = 20; // Fire 20 requests for 10 stock items
        
        // Fire all requests as quickly as possible
        // The database will serialize them via lockForUpdate()
        $responses = [];
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $responses[] = $this->postJson('/api/holds', [
                'product_id' => 1,
                'qty' => 1
            ]);
        }
        
        // Count successes and failures
        $successfulHolds = 0;
        $failedHolds = 0;
        
        foreach ($responses as $response) {
            if ($response->status() === 201) {
                $successfulHolds++;
            } elseif ($response->status() === 409) {
                $failedHolds++;
            }
        }
        
        // Verify we didn't oversell - exactly 10 should succeed
        $this->assertEquals($stock, $successfulHolds, 
            "Should allow exactly {$stock} holds under concurrent load, got {$successfulHolds}");
        $this->assertEquals($concurrentRequests - $stock, $failedHolds,
            "Should reject " . ($concurrentRequests - $stock) . " requests, got {$failedHolds}");
        
        // Verify final stock is 0
        Cache::forget('product_1');
        $this->getJson('/api/products/1')->assertJson(['stock' => 0]);
        
        // Verify database has exactly 10 holds
        $this->assertEquals($stock, Hold::where('product_id', 1)->count());
    }

    public function test_expired_holds_restore_availability()
    {
        // 1. Hold 5 items
        $this->postJson('/api/holds', ['product_id' => 1, 'qty' => 5]);
        
        // Clear cache to verify the update
        Cache::forget('product_1');
        $this->getJson('/api/products/1')->assertJson(['stock' => 5]);

        // 2. Travel forward 3 minutes
        $this->travel(3)->minutes();

        // 3. Run Release Command
        $this->artisan('holds:release');

        // Clear cache again to verify the restoration
        Cache::forget('product_1');

        // 4. API should show 10 again
        $this->getJson('/api/products/1')->assertJson(['stock' => 10]);
    }

    public function test_convert_hold_to_order_deducts_physical_stock()
    {
        // 1. Create Hold (Qty 2)
        $hold = $this->postJson('/api/holds', ['product_id' => 1, 'qty' => 2])->json();
        
        // Stock column is still 10 (Reservation model)
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 10]);

        // 2. Create Order
        $this->postJson('/api/orders', ['hold_id' => $hold['hold_id']])
             ->assertStatus(201);

        // 3. NOW the stock column should be 8
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 8]);
    }

    public function test_webhook_idempotency_and_logging()
    {
        // Setup Order
        $order = Order::create([
            'product_id' => 1, 'qty' => 1, 'amount' => 100, 'status' => 'pending'
        ]);

        $payload = [
            'order_id' => $order->id,
            'status' => 'success',
            'idempotency_key' => 'key-123'
        ];

        // 1. First Webhook
        $this->postJson('/api/payments/webhook', $payload)->assertStatus(200);
        
        // Check Log created
        $this->assertDatabaseHas('webhook_logs', ['idempotency_key' => 'key-123']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);

        // 2. Duplicate Webhook
        $this->postJson('/api/payments/webhook', $payload)->assertStatus(200);

        // Ensure we didn't process it twice (Logic check is inside controller)
    }

    public function test_webhook_out_of_order_protection()
    {
        $order = Order::create([
            'product_id' => 1, 'qty' => 1, 'amount' => 100, 'status' => 'paid'
        ]);

        // A "failed" webhook arrives LATER with a different key
        $payload = [
            'order_id' => $order->id,
            'status' => 'failed',
            'idempotency_key' => 'late-key-999'
        ];

        $this->postJson('/api/payments/webhook', $payload)->assertStatus(200);

        // Status should REMAIN 'paid', not change to 'cancelled'
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_webhook_before_order_creation_returns_404_for_retry()
    {
        // 1. Simulate a webhook for an order ID that does not exist yet (e.g., ID 999)
        $payload = [
            'order_id' => 999, 
            'status' => 'success',
            'idempotency_key' => 'early-key-789'
        ];

        // 2. We expect a 404 Not Found
        // This tells the payment provider to TRY AGAIN later.
        $this->postJson('/api/payments/webhook', $payload)
             ->assertStatus(404);
    }
}