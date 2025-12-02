# Flash Sale API

A high-concurrency API for handling flash sales with limited stock, ensuring no overselling using database locking.

## Features

- **Concurrency Safe**: Utilizes `lockForUpdate` and database transactions to prevent race conditions and overselling.
- **Hold System**: Implements a temporary reservation system that holds stock for a user for 2 minutes, preventing items from being sold to others.
- **Auto-Release**: A background command automatically releases expired holds, returning stock to the available pool.
- **Idempotent Webhooks**: Safely handles duplicate payment notifications using an `idempotency_key`, ensuring that a payment is processed only once.

## Requirements

- PHP 8.2+
- MySQL
- Composer

## Setup Instructions

1.  **Clone the repository and navigate to the project directory:**
    ```bash
    git clone https://your-repository-url/flash-sale-api.git
    cd flash-sale-api
    ```

2.  **Install PHP dependencies:**
    ```bash
    composer install
    ```

3.  **Create and configure your environment file:**
    ```bash
    cp .env.example .env
    ```
    Update your `.env` file with your database credentials (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

4.  **Generate an application key:**
    ```bash
    php artisan key:generate
    ```

5.  **Run the database migrations:**
    ```bash
    php artisan migrate
    ```

6.  **Seed the database with initial product data:**
    ```bash
    php artisan db:seed --class=ProductSeeder
    ```

7.  **Start the local development server:**
    ```bash
    php artisan serve
    ```

## Testing

The project includes a comprehensive test suite to ensure reliability, especially under concurrent conditions.

- **Run all tests:**
  ```bash
  php artisan test
  ```
- **Test Coverage**: The suite covers critical paths, including concurrency safety, hold creation and expiry, and idempotent webhook handling.

## Architecture & Assumptions

- **Stock Deduction**: Stock is deducted from the product's inventory as soon as a "Hold" is successfully created.
- **Hold Expiry**: Expired holds are not released automatically on expiry time. They are released by the `holds:release` command, which should be scheduled to run periodically (e.g., every minute via a cron job).
- **Idempotency**: Webhooks are designed to be idempotent. To prevent duplicate processing, every webhook request must include a unique `idempotency_key` in its payload.
- **Logging**: All significant events and errors are logged in `storage/logs/laravel.log`.

## API Endpoints

| Method | Endpoint                    | Description                                       | Inputs                                     |
| :----- | :-------------------------- | :------------------------------------------------ | :----------------------------------------- |
| `GET`  | `/api/products/{id}`        | Retrieves product details and available stock.    | -                                          |
| `POST` | `/api/holds`                | Creates a temporary hold on a product's stock.    | `product_id` (integer), `qty` (integer)    |
| `POST` | `/api/orders`               | Converts a hold into a confirmed order.           | `hold_id` (integer)                        |
| `POST` | `/api/payments/webhook`     | Handles payment status updates from a provider.   | `order_id`, `status`, `idempotency_key`      |

## Management Commands

- **Release Expired Holds**: This command should be scheduled to run periodically to release holds that have exceeded their 2-minute reservation window.
  ```bash
  php artisan holds:release
  ```
