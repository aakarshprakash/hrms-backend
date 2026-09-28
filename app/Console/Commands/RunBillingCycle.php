<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily: trials that ended (start billing, or fall back to the free core),
 * periods that ended (renew with an invoice, or end a cancelled plan), and
 * invoices overdue past the grace period (past due).
 */
class RunBillingCycle extends Command
{
    protected $signature = 'billing:run {--date= : Run as of this date}';

    protected $description = 'End trials, renew subscriptions with invoices, flag overdue payments';

    public function handle(SubscriptionService $subscriptions): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date'))->startOfDay() : null;
        $stats = $subscriptions->runCycle($today);

        $this->info(sprintf('Trials ended: %d, renewed: %d, ended: %d, past due: %d.',
            $stats['trials_ended'], $stats['renewed'], $stats['cancelled'], $stats['past_due']));

        return self::SUCCESS;
    }
}
