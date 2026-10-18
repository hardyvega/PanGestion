<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(EffectivePermissionResolver $resolver): void
    {
        Gate::before(static function (User $user, string $ability) use ($resolver): ?bool {
            $permission = PermissionCode::tryFrom($ability);

            return $permission === null
                ? null
                : $resolver->allows($user, $permission);
        });
    }
}
