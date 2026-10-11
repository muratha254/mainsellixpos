<?php

namespace App\Console\Commands;

use App\Services\LoyaltyService;
use Illuminate\Console\Command;

class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire';

    protected $description = 'Expire customer loyalty points that have passed their expiry date';

    public function handle(LoyaltyService $loyalty): int
    {
        $count = $loyalty->expireAll();
        $this->info('Expired ' . $count . ' loyalty lot(s).');

        return 0;
    }
}
