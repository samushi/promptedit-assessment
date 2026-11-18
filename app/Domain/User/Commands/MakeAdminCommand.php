<?php

declare(strict_types=1);

namespace App\Domain\User\Commands;

use App\Domain\User\Repository\UserRepository;
use Illuminate\Console\Command;

final class MakeAdminCommand extends Command
{
    protected $signature = 'users:make-admin {email : The user who may open the admin report} {--revoke : Remove operator access instead}';

    protected $description = 'Grant (or revoke) operator access: the admin report and Horizon';

    public function handle(UserRepository $users): int
    {
        $user = $users->findByEmail((string) $this->argument('email'));

        if ($user === null) {
            $this->components->error('No user with that email address.');

            return self::FAILURE;
        }

        $users->setAdmin($user, ! $this->option('revoke'));

        $this->components->info($user->is_admin ? "{$user->email} is now an operator." : "{$user->email} is no longer an operator.");

        return self::SUCCESS;
    }
}
