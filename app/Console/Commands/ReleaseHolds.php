<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Hold;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReleaseHolds extends Command
{
    protected $signature = 'holds:release';
    protected $description = 'Release expired holds';

    public function handle()
    {
        // Fix Critical Issue #1 logic:
        // We no longer need to increment stock, because Holds didn't decrement it.
        // We just delete the expired reservations so the ProductController 
        // stops counting them against the available total.

        // Get expired holds with their product IDs before deletion
        $expiredHolds = Hold::where('expires_at', '<', now())->get();
        $affectedProductIds = $expiredHolds->pluck('product_id')->unique();
        
        $deleted = Hold::where('expires_at', '<', now())->delete();

        if ($deleted > 0) {
            // Invalidate cache for all affected products
            foreach ($affectedProductIds as $productId) {
                Cache::forget("product_{$productId}");
            }
            
            // Log expiry metric
            Log::info('Expired holds released', [
                'count' => $deleted,
                'affected_products' => $affectedProductIds->toArray(),
                'metric' => 'holds_expired'
            ]);
            
            $this->info("Released {$deleted} expired holds.");
        } else {
            $this->info('No expired holds found.');
        }
    }
}