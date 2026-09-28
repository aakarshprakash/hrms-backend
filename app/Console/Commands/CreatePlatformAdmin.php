<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Access\Roles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:admin
        {email : Email address of the platform operator}
        {--name=Platform Admin : Display name}
        {--password= : Password (prompted if omitted; generated with --generate)}
        {--generate : Generate a random password and print it once}';

    protected $description = 'Create (or promote) the SaaS platform super admin account';

    public function handle(TenantContext $context): int
    {
        $email = strtolower($this->argument('email'));

        return $context->withoutScoping(function () use ($email) {
            $existing = User::where('email', $email)->first();

            if ($existing && $existing->company_id !== null) {
                $this->error("{$email} belongs to an organisation; a platform admin must be a separate account.");

                return self::FAILURE;
            }

            $password = $this->option('password');
            if ($this->option('generate')) {
                $password = Str::password(16);
            } elseif (! $password && ! $existing) {
                $password = $this->secret('Password (min 12 characters)');
            }

            if (! $existing && strlen((string) $password) < 12) {
                $this->error('Password must be at least 12 characters.');

                return self::FAILURE;
            }

            $user = $existing ?? new User(['email' => $email]);
            $user->forceFill([
                'name' => $existing?->name ?? $this->option('name'),
                'is_super_admin' => true,
                'user_type' => 'system',
                'is_active' => true,
            ]);
            if ($password) {
                $user->password = Hash::make($password);
                $user->password_changed_at = now();
            }
            $user->save();
            $user->syncRoles([Roles::SUPER_ADMIN]);

            $this->info(($existing ? 'Updated' : 'Created') . " platform admin {$email}.");
            if ($this->option('generate')) {
                $this->warn("Password: {$password}  (shown once -- store it securely)");
            }

            return self::SUCCESS;
        });
    }
}
