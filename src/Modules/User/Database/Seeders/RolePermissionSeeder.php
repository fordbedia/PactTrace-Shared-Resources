<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Projects the Role/Permission enums onto the spatie tables.
 *
 * Safe to run repeatedly — it converges the database on whatever the enums
 * currently say, including removing permissions that have been deleted from the
 * catalogue. That pruning is deliberate: a permission left behind in the
 * database after being dropped from the enum would keep granting access that no
 * longer exists anywhere in the code.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        // Stale cache here would make every check below read the old catalogue.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->syncPermissions($guard);

        // syncRoles() below resolves permissions by name via the registrar's
        // cache. That cache is normally kept fresh by a Permission::saved
        // model-event listener, but this seeder is commonly invoked from
        // DatabaseSeeder::run(), which uses Laravel's WithoutModelEvents
        // trait — that suppresses model events (and therefore that listener)
        // for the whole seeding run. Without this explicit refresh, the
        // permissions just created above are invisible to syncRoles() and it
        // fails with PermissionDoesNotExist on the very first permission.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->syncRoles($guard);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function syncPermissions(string $guard): void
    {
        foreach (Permission::values() as $name) {
            SpatiePermission::findOrCreate($name, $guard);
        }

        SpatiePermission::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', Permission::values())
            ->delete();
    }

    private function syncRoles(string $guard): void
    {
        foreach (Role::cases() as $role) {
            $spatieRole = SpatieRole::findOrCreate($role->value, $guard);

            // syncPermissions (not givePermissionTo) so a permission revoked in
            // the enum is actually withdrawn from the role on the next run.
            $spatieRole->syncPermissions(
                array_map(
                    static fn (Permission $permission): string => $permission->value,
                    $role->permissions(),
                )
            );
        }
    }
}
