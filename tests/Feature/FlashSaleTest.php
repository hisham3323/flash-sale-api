<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSaleTest extends TestCase
{
    use RefreshDatabase; // This resets the database for every test so they are clean

    protected function setUp(): void
    {
        parent::setUp();
        // Create a product with 10 items for every test
        Product::create([
            'id' => 1,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);
    }

    public function test_create_hold_reduces_stock()
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => 1,
            'qty' => 4
        ]);

        $response->assertStatus(201);
        
        // Stock was 10, bought 4, should be 6
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 6]);
    }

    public function test_cannot_oversell_stock()
    {
        // Try to hold 11 items (only 10 available)
        $response = $this->postJson('/api/holds', [
            'product_id' => 1,
            'qty' => 11
        ]);

        $response->assertStatus(409); // Conflict
        
        // Stock should remain 10
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 10]);
    }

    public function test_expired_holds_return_stock()
    {
        // 1. Create a hold for 5 items
        $this->postJson('/api/holds', ['product_id' => 1, 'qty' => 5]);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);

        // 2. Travel into the future (3 minutes later)
        $this->travel(3)->minutes();

        // 3. Run the release command
        $this->artisan('holds:release')
             ->assertExitCode(0);

        // 4. Stock should be back to 10
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 10]);
        // Hold should be gone
        $this->assertDatabaseCount('holds', 0);
    }

    public function test_convert_hold_to_order()
    {
        // 1. Create Hold
        $holdResponse = $this->postJson('/api/holds', ['product_id' => 1, 'qty' => 2]);
        $holdId = $holdResponse->json('hold_id');

        // 2. Create Order
        $response = $this->postJson('/api/orders', ['hold_id' => $holdId]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('orders', ['status' => 'pending', 'amount' => 200.00]);
        $this->assertDatabaseMissing('holds', ['id' => $holdId]); // Hold should be deleted
    }

    public function test_payment_webhook_success_is_idempotent()
    {
        // Setup: Create an Order
        $order = Order::create([
            'product_id' => 1,
            'qty' => 2,
            'amount' => 200,
            'status' => 'pending'
        ]);

        $payload = [
            'order_id' => $order->id,
            'status' => 'success',
            'idempotency_key' => 'unique-key-123'
        ];

        // 1. First Webhook Call
        $this->postJson('/api/payments/webhook', $payload)->assertStatus(200);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);

        // 2. Second Webhook Call (Duplicate)
        $this->postJson('/api/payments/webhook', $payload)->assertStatus(200);
        
        // Should stay paid, no errors
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_payment_failed_restores_stock()
    {
        // Setup: Order for 2 items. Stock is currently 10.
        // We manually reduce stock to simulate the hold having taken them.
        $product = Product::find(1);
        $product->stock = 8; 
        $product->save();

        $order = Order::create([
            'product_id' => 1,
            'qty' => 2,
            'amount' => 200,
            'status' => 'pending'
        ]);

        $payload = [
            'order_id' => $order->id,
            'status' => 'failed',
            'idempotency_key' => 'fail-key-456'
        ];

        // 1. Webhook Call (Failed)
        $this->postJson('/api/payments/webhook', $payload)->assertStatus(200);

        // 2. Order should be cancelled
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);

        // 3. Stock should increase by 2 (8 + 2 = 10)
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 10]);
    }

    public function test_webhook_before_order_creation_returns_404_for_retry()
    {
        // 1. Simulate a webhook for an order ID that does not exist yet (e.g., ID 999)
        // This simulates the "Out of order" scenario where webhook arrives before the DB has the order.
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