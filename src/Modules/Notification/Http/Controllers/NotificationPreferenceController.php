<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Preferences\NotificationPreferenceResolver;
use PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases\ResetNotificationPreferences;
use PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases\UpdateNotificationPreference;
use PactTrackSDK\SharedResources\Modules\Notification\Http\Requests\UpdateNotificationPreferenceRequest;

/**
 * The `/notification` (Notification Preferences) screen's backend.
 *
 * Real `auth:sanctum`, no policy — every action is scoped to
 * `$request->user()` itself, exactly like the User module's ProfileController.
 *
 *   GET   /api/v1/notification-preferences         effective catalogue for the user
 *   PATCH /api/v1/notification-preferences/{key}    per-toggle auto-save
 *   POST  /api/v1/notification-preferences/reset    "Reset to Defaults"
 *
 * Responses carry the effective row shape (defaults folded with the user's
 * overrides, locked channels forced on) so the screen never has to merge two
 * sources itself. Only Email is rendered today; in_app/sms travel on the wire
 * for when those channels ship.
 */
class NotificationPreferenceController extends Controller
{
    public function index(Request $request, NotificationPreferenceResolver $resolver): JsonResponse
    {
        return response()->json([
            'data' => $resolver->catalogueForUser($request->user()),
        ]);
    }

    public function update(
        UpdateNotificationPreferenceRequest $request,
        string $key,
        UpdateNotificationPreference $useCase,
    ): JsonResponse {
        $row = $useCase->handle($request->user(), $key, $request->channels());

        return response()->json(['data' => $row]);
    }

    public function reset(Request $request, ResetNotificationPreferences $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->handle($request->user()),
        ]);
    }
}
