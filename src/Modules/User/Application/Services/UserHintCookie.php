<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Application\Services;

use Illuminate\Support\Facades\Cookie;
use PactTraceSDK\SharedResources\Modules\User\Models\User;

/**
 * A small, non-httpOnly cookie carrying only {id, name, email} — enough for
 * the SPA to paint a name/avatar on first render instead of a loading
 * skeleton while GET /api/user resolves. It is a UI hint only: the httpOnly
 * `pacttrace-session` cookie remains the actual credential, and nothing here
 * (roles, permissions, tenant data) should ever be trusted for an
 * authorization decision — this cookie is readable, and therefore editable,
 * by any script running on the page.
 */
class UserHintCookie
{
    public const NAME = 'pacttrace-user-hint';

    public function attach(User $user): void
    {
        Cookie::queue(Cookie::make(
            self::NAME,
            json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ], JSON_THROW_ON_ERROR),
            minutes: (int) config('session.lifetime'),
            httpOnly: false,
        ));
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::NAME));
    }
}
