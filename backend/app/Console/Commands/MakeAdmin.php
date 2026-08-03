<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Grant or revoke the admin role for a user by email. */
class MakeAdmin extends Command
{
    protected $signature = 'transoria:make-admin {email} {--revoke : Remove admin instead of granting}';

    protected $description = 'Grant (or revoke with --revoke) the admin role for a user';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
        $this->info(($this->option('revoke') ? 'Revoked admin from ' : 'Granted admin to ').$user->email.'.');

        return self::SUCCESS;
    }
}
