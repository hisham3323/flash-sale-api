<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Hold;

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

        $deleted = Hold::where('expires_at', '<', now())->delete();

        if ($deleted > 0) {
            $this->info("Released {$deleted} expired holds.");
        } else {
            $this->info('No expired holds found.');
        }
    }
}