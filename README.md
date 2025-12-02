# Flash Sale API

A high-concurrency Laravel API for handling flash sales with limited stock.

## Features
* **Concurrency Safe:** Uses database transactions and `lockForUpdate` to prevent overselling.
* **Hold System:** Temporary reservation (2 minutes) before purchase.
* **Auto-Release:** Background command releases expired holds.
* **Idempotent Webhooks:** Handles duplicate or out-of-order payment notifications safely.

## Requirements
* PHP 8.2+
* MySQL
* Composer

## Setup Instructions

1.  **Clone the repository**
    ```bash
    git clone <repository-url>
    cd <folder-name>
    ```

2.  **Install Dependencies**
    ```bash
    composer install
    ```

3.  **Configure Environment**
    Copy the example env file and configure your database settings:
    ```bash
    cp .env.example .env
    php artisan key:generate
    ```
    *Update `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`.*

4.  **Run Migrations & Seeders**
    ```bash
    php artisan migrate
    php artisan db:seed --class=ProductSeeder
    ```

5.  **Start the Server**
    ```bash
    php artisan serve
    ```

## Testing

Run the automated test suite to verify concurrency and logic:
```bash
php artisan test