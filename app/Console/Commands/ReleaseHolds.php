<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;

class ReleaseHolds extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'holds:release';

    /**
     * The console command description.
     */
    protected $description = 'Release expired holds and return stock to products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired holds...');

        // Find holds that have expired
        $expiredHolds = Hold::where('expires_at', '<', now())->get();

        if ($expiredHolds->isEmpty()) {
            $this->info('No expired holds found.');
            return;
        }

        $count = 0;

        foreach ($expiredHolds as $hold) {
            DB::transaction(function () use ($hold, &$count) {
                // Refetch the hold with a lock to ensure no one else is processing it
                $lockedHold = Hold::where('id', $hold->id)->lockForUpdate()->first();

                // If it still exists (wasn't processed by another parallel job)
                if ($lockedHold) {
                    // 1. Add quantity back to product stock
                    $lockedHold->product->increment('stock', $lockedHold->qty);
                    
                    // 2. Delete the hold
                    $lockedHold->delete();
                    
                    $count++;
                }
            });
        }

        $this->info("Successfully released {$count} holds.");
    }
}