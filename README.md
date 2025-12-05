# Flash Sale API

A high-concurrency Laravel API designed to handle flash sales with finite stock.

## 🚀 Features & Architecture

* **Concurrency Safe:** Uses `DB::lockForUpdate()` and strict transactions to prevent race conditions (overselling).
* **Reservation System:** "Holds" are created as temporary reservations. **Physical stock is only deducted upon successful Order creation.**
* **Dynamic Availability:** `GET /api/products/{id}` calculates stock dynamically (`Physical Stock` - `Active Holds`) and uses **Caching** (5s TTL) with automatic invalidation on stock changes.
* **Idempotent Webhooks:** A dedicated `webhook_logs` table tracks every payment event. Out-of-order or duplicate events are safely ignored to ensure data consistency.
* **Auto-Cleanup:** A scheduled background command automatically releases expired reservations.
* **Structured Logging:** Comprehensive metrics for stock contention, webhook deduplication, retries, and payment events.
* **Database Constraints:** `unsignedInteger` type prevents negative stock values at the database level.

## 🛠 Prerequisites

* PHP 8.2+
* MySQL
* Composer

## ⚙️ Setup Instructions

1.  **Clone the repository**
    ```bash
    git clone <repository-url>
    cd <folder-name>
    ```

2.  **Install Dependencies**
    ```bash
    composer install
    ```

3.  **Environment Setup**
    ```bash
    cp .env.example .env
    # Configure DB_DATABASE, DB_USERNAME, DB_PASSWORD in .env
    php artisan key:generate
    ```

4.  **Database**
    ```bash
    php artisan migrate
    php artisan db:seed --class=ProductSeeder
    ```

5.  **Start the Server**
    ```bash
    php artisan serve
    ```
    *API URL: http://127.0.0.1:8000*

6.  **Start Task Scheduler**
    To enable the auto-release of expired holds, you must run the scheduler:
    ```bash
    php artisan schedule:work
    ```

## 🧪 Testing

The automated test suite covers concurrency boundaries (rapid-fire and parallel requests), expiry logic, caching, and webhook idempotency.

```bash
php artisan test
```

Test coverage includes:
* **Sequential rapid-fire requests** to verify stock boundary enforcement
* **Parallel concurrent requests** simulation to test database locking under load
* **Hold expiry** and automatic availability restoration
* **Webhook idempotency** (duplicate handling)
* **Out-of-order webhook** protection
* **Webhook before order creation** (404 retry scenario)

## 📝 Assumptions & Invariants

* **Stock Authority:** The Database is the single source of truth. The API never trusts client-side stock data.
* **Stock Model:** Physical stock is only decremented when an Order is created. Holds are "soft reservations" that reduce available stock calculation but do not touch the `products.stock` column until checkout.
* **Concurrency:** Database row-level locking (`lockForUpdate`) ensures that two requests cannot modify the same product or order simultaneously.
* **Hold Duration:** Holds expire strictly after 2 minutes.
* **Webhook Delivery:** Webhooks may arrive multiple times or out of order. The system uses `idempotency_key` and strict status checks (e.g., ignoring 'failed' if already 'paid') to ensure the correct final state.
* **Cache Invalidation:** Product cache is automatically invalidated when:
  * A hold is created (reduces available stock)
  * Expired holds are released (increases available stock)
  * An order is created (reduces physical stock)
  * Payment fails and stock is returned (increases physical stock)
* **Data Integrity:** Database uses `unsignedInteger` for stock to prevent negative values at the schema level.

## 📊 Logs & Metrics

* **Application Logs:** `storage/logs/laravel.log` contains structured logging with metrics:
  * `stock_contention` - Logged when hold creation fails due to insufficient stock
  * `hold_created` - Logged on successful hold creation
  * `holds_expired` - Logged when expired holds are released (includes count and affected products)
  * `webhook_dedupe` - Logged when duplicate webhooks are detected and ignored
  * `webhook_retry_required` - Logged when webhook arrives before order creation
  * `webhook_out_of_order` - Logged when webhook is ignored due to order already processed
  * `payment_success` - Logged when payment webhook successfully processes
  * `payment_failed` - Logged when payment fails and stock is returned

* **Webhook Logs:** All incoming webhooks are persisted to the `webhook_logs` database table. This provides a full audit trail of payment events, including payloads and timestamps.

* **Stock Contention:** Failed hold attempts (overselling) return HTTP 409 (Conflict) and are logged with the `stock_contention` metric.

## 📡 Endpoints

| Method | Endpoint | Description | Inputs |
|--------|----------|-------------|--------|
| GET | `/api/products/{id}` | View available stock (Cached). | N/A |
| POST | `/api/holds` | Reserve items (2 mins). | `{ "product_id": 1, "qty": 1 }` |
| POST | `/api/orders` | Convert Hold to Order. | `{ "hold_id": 1 }` |
| POST | `/api/payments/webhook` | Process Payment. | `{ "order_id": 1, "status": "success", "idempotency_key": "..." }` |

## ⏰ Background Processing

The `holds:release` command runs every minute to clean up expired holds.

**In Development:**
```bash
php artisan schedule:work
```

**In Production (Cron Setup):**
Add this to your server's crontab:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```
