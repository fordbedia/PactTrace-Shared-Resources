<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserHintCookie;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile\ChangeOwnPassword;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile\DeleteOwnAccount;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile\GetAccountDeletionEligibility;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile\UpdateAvatar;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile\UpdateProfile;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\AccountDeletionBlockedException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\AccountDeletionConfirmationException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\InvalidCurrentPasswordException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\AccountDeletionPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionBlocker;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\DeleteAccountRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\UpdateAvatarRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\UpdatePasswordRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\UpdateProfileRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\UserResource;

/**
 * The signed-in user's own account screen (`/profile` — Dashboard/Your
 * Profile.html). Behind real `auth:sanctum`; every action is scoped to
 * `$request->user()` itself, so there's no policy to run — a user can always
 * read and change their own profile.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly UpdateProfile $updateProfile,
        private readonly UpdateAvatar $updateAvatar,
        private readonly ChangeOwnPassword $changeOwnPassword,
        private readonly GetAccountDeletionEligibility $deletionEligibility,
        private readonly DeleteOwnAccount $deleteOwnAccount,
        private readonly UserAuthentication $authentication,
        private readonly UserHintCookie $hintCookie,
    ) {
    }

    /**
     * PATCH /api/v1/profile — identity card "Save Changes".
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->updateProfile->handle(
            $request->user(),
            (string) $request->validated('first_name'),
            (string) $request->validated('last_name'),
            (string) $request->validated('email'),
            $request->validated('phone'),
        );

        // Re-attach the plaintext hint cookie — name/email may have changed.
        $this->hintCookie->attach($user);

        return response()->json([
            'data' => new UserResource($user->loadAuthPayload()),
        ]);
    }

    /**
     * POST /api/v1/profile/avatar — identity card camera button.
     *
     * Multipart, one `avatar` file. Returns the fresh UserResource so the SPA
     * can swap the photo everywhere it renders (`avatar_url`).
     */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->updateAvatar->handle(
            $request->user(),
            $request->file('avatar'),
        );

        return response()->json([
            'data' => new UserResource($user->loadAuthPayload()),
        ]);
    }

    /**
     * PUT /api/v1/profile/password — password card "Update Password".
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        try {
            $this->changeOwnPassword->handle(
                $request->user(),
                (string) $request->validated('current_password'),
                (string) $request->validated('password'),
            );
        } catch (InvalidCurrentPasswordException $e) {
            throw ValidationException::withMessages([
                'current_password' => [$e->getMessage()],
            ]);
        }

        // Keep THIS session alive. Sanctum's AuthenticateSession middleware
        // (on every stateful /api/* request) compares the session's stored
        // password hash to the user's current one and force-logs-out on a
        // mismatch — so without re-storing it here, the SPA's very next
        // `GET /api/user` poll after a password change would 401 and bounce
        // the user to sign-in. Mirrors the guard list the middleware itself
        // iterates (`config('sanctum.guard')`).
        if ($request->hasSession()) {
            $freshHash = $request->user()->fresh()?->getAuthPassword();
            foreach ((array) config('sanctum.guard', ['web']) as $guard) {
                $request->session()->put("password_hash_{$guard}", $freshHash);
            }
        }

        return response()->json(null, 204);
    }

    /**
     * GET /api/v1/profile/deletion-eligibility — the delete modal's
     * pre-flight. `{ eligible, blockers: [{ code, label, detail }],
     * active_staff_count }`.
     *
     * `active_staff_count` is informational, never a blocker — it lets the
     * modal warn that deleting the account also removes portal access for
     * other active team members rather than doing it silently.
     */
    public function deletionEligibility(Request $request): JsonResponse
    {
        $signals = $this->deletionEligibility->handle(
            (int) $request->user()->provider_id,
            (int) $request->user()->id,
        );
        $blockers = AccountDeletionPolicy::blockers($signals);

        return response()->json([
            'eligible' => $blockers === [],
            'blockers' => $this->serializeBlockers($blockers),
            'active_staff_count' => $signals->activeStaffCount,
        ]);
    }

    /**
     * DELETE /api/v1/profile — the delete modal's confirmed submission.
     *
     * 204 + a cleared session on success. A still-blocked account is a 422
     * `{ reason: 'blocked', blockers }`; a bad name/password is a 422
     * validation error on that field.
     */
    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        try {
            $this->deleteOwnAccount->handle(
                $request->user(),
                (string) $request->validated('name'),
                (string) $request->validated('password'),
            );
        } catch (AccountDeletionBlockedException $e) {
            return response()->json([
                'message' => "Your account can't be deleted yet.",
                'reason' => 'blocked',
                'blockers' => $this->serializeBlockers($e->blockers),
            ], 422);
        } catch (AccountDeletionConfirmationException $e) {
            $field = $e->reason === 'name' ? 'name' : 'password';
            $message = $e->reason === 'name'
                ? "That doesn't match the name on this account."
                : 'That password is incorrect.';

            throw ValidationException::withMessages([$field => [$message]]);
        }

        // The account is gone from the user's point of view — end the session
        // so the SPA's next `GET /api/user` 401s and it redirects to sign-in.
        $this->authentication->logout();
        $this->hintCookie->forget();

        return response()->json(null, 204);
    }

    /**
     * @param  list<AccountDeletionBlocker>  $blockers
     * @return list<array{code: string, label: string, detail: string}>
     */
    private function serializeBlockers(array $blockers): array
    {
        return array_map(static fn (AccountDeletionBlocker $blocker): array => [
            'code' => $blocker->value,
            'label' => $blocker->label(),
            'detail' => $blocker->resolution(),
        ], $blockers);
    }
}
