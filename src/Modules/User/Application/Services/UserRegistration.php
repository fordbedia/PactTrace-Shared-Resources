<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Application\Services;

use Illuminate\Support\Str;
use PactTraceSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTraceSDK\SharedResources\Modules\User\Models\User;
use RuntimeException;

/**
 * Account-lifecycle operations against the `users` table.
 *
 * Siblings in this folder are the other things one can do to a user account —
 * UserAuthentication (sign in / sign out), UserPasswordReset, and so on. They
 * share a layer, not a base class: each owns one slice of account behaviour and
 * is composed by whichever use case needs it.
 *
 * The distinction from Application/UseCases/ is reuse, not size. A use case is
 * one whole thing a person asked the system to do ("sign up as a provider").
 * This is a *step* that several use cases need: provider signup creates a login
 * with the owner role, and accepting a client invitation creates one with the
 * client role (see .claude/rules/client.md — `clients.user_id` stays null until
 * then). Both want identical behaviour around normalisation, duplicate
 * rejection and role assignment, and neither should own it alone.
 *
 * Application layer rather than Infrastructure: everything here is decision,
 * not I/O. The one dependency is the UserRepository *port*, so nothing in this
 * class knows Eloquent exists and it can be exercised against a fake.
 */
class UserRegistration
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Create a login and put it in a role.
     *
     * Deliberately does not take a provider id: at signup the tenant does not
     * exist yet (`providers.owner_user_id` is a non-null FK to this very row),
     * so the caller attaches it afterwards with attachToProvider(). Callers who
     * do already know the tenant — invitation acceptance — simply call both in
     * a row.
     *
     * @param  string  $password  Plain text. The User model casts
     *                            `password => 'hashed'`; pre-hashing here would
     *                            hash it twice and lock the user out.
     *
     * @throws RuntimeException when the email is already registered
     */
    public function register(string $name, string $email, string $password, Role $role): User
    {
        $email = $this->normalizeEmail($email);

        $this->guardEmailIsAvailable($email);

        $user = $this->users->create([
            'name' => trim($name),
            'email' => $email,
            'password' => $password,
        ]);

        $this->users->assignRole($user, $role);

        return $user;
    }

    /**
     * Place an existing login inside a tenant.
     *
     * Separate from register() because of the FK cycle described above, but
     * also because it is meaningful on its own: this is how a staff member is
     * moved into a provider, not only how signup finishes.
     */
    public function attachToProvider(User $user, int $providerId): User
    {
        return $this->users->assignProvider($user, $providerId);
    }

    /**
     * Lowercased and trimmed.
     *
     * Not cosmetic: `users.email` is compared with `=` and carries a UNIQUE
     * index, so "Jane@Example.com" and "jane@example.com" would otherwise be
     * two accounts that no one can tell apart at the login form.
     */
    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function isEmailAvailable(string $email): bool
    {
        return ! $this->users->emailExists($this->normalizeEmail($email));
    }

    /**
     * The FormRequest should have rejected a duplicate long before here; this
     * is the last line of defence before the UNIQUE index, so callers get a
     * domain error rather than a QueryException they have to decode.
     */
    private function guardEmailIsAvailable(string $email): void
    {
        if ($this->users->emailExists($email)) {
            throw new RuntimeException("An account already exists for [{$email}].");
        }
    }
}
