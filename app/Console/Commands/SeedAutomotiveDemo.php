<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Tenancy\TenantPurger;
use Database\Seeders\DemoAutomotiveSeeder;
use Illuminate\Console\Command;

class SeedAutomotiveDemo extends Command
{
    protected $signature = 'demo:automotive {--fresh : Delete the existing demo tenant first}';

    protected $description = 'Create the demo-ready "Velocity Motors" automotive tenant (bike & car showrooms)';

    public function handle(TenantPurger $purger): int
    {
        $existing = Company::where('slug', DemoAutomotiveSeeder::SLUG)->first();

        if ($existing && $this->option('fresh')) {
            $this->warn("Removing existing demo tenant #{$existing->id}…");
            $purger->purge($existing);
        }

        $started = microtime(true);
        $this->call('db:seed', ['--class' => DemoAutomotiveSeeder::class, '--force' => true]);
        $this->info(sprintf('Done in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
