<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;

/**
 * Validation for `POST /api/user/register`.
 *
 * Everything here is about giving the signup form a usable 422 instead of a
 * 500. The domain still enforces the same rules underneath — Subdomain throws
 * on a malformed value and the UNIQUE indexes are the real authority — but an
 * exception surfacing as a server error tells the person filling in the form
 * nothing at all.
 */
class StoreRegistrationRequest extends FormRequest
{
    /** Registration is public by definition; there is no actor to authorise. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating, so the uniqueness checks below compare the
     * same string that will eventually be written. Without this, signing up as
     * "Jane@Example.com" passes a `unique` check against a stored
     * "jane@example.com" and then fails on the index inside the transaction.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'email' => $this->filled('email') ? Str::lower(trim((string) $this->input('email'))) : null,
            'subdomain' => $this->filled('subdomain') ? Str::lower(trim((string) $this->input('subdomain'))) : null,
        ], static fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email'),
            ],

            // `confirmed` pairs with the `password_confirmation` field the
            // signup form already sends. 12 characters matches the frontend's
            // own rule — keep the two in step or one will silently win.
            'password' => ['required', 'string', 'min:12', 'confirmed'],

            'business_name' => ['required', 'string', 'max:255'],

            // Optional: omitted means "derive one from the business name",
            // which SubdomainAllocator does during registration. Present means
            // the user chose it deliberately, so it is held to the full rule
            // and a collision is reported rather than silently renamed.
            'subdomain' => [
                'nullable', 'string', 'max:' . Subdomain::MAX_LENGTH,
                $this->validSubdomain(),
                Rule::unique('providers', 'subdomain'),
            ],

            'plan' => ['sometimes', Rule::in(['starter', 'professional', 'firm'])],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'That email is already registered.',
            'subdomain.unique' => 'That portal address is taken. Try another.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }

    /**
     * Defers to the value object instead of restating its regex here.
     *
     * The rules (DNS grammar, length, the reserved-hostname list) live in
     * Subdomain and are covered by SubdomainTest; duplicating them as a
     * `regex:` rule would give two definitions that drift the first time one
     * changes.
     */
    private function validSubdomain(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            try {
                Subdomain::fromString((string) $value);
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
    }
}
