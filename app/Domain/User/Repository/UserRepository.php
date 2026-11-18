<?php

declare(strict_types=1);

namespace App\Domain\User\Repository;

use App\Domain\User\Models\User;
use App\Support\Repositories;

/**
 * @extends Repositories<User>
 */
class UserRepository extends Repositories
{
    public function findByEmail(string $email): ?User
    {
        return $this->query()->where('email', $email)->first();
    }

    public function setAdmin(User $user, bool $isAdmin): void
    {
        $user->forceFill(['is_admin' => $isAdmin])->save();
    }

    protected function getModel(): User
    {
        return new User;
    }
}
