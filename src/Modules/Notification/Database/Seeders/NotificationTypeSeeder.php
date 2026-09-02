<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Database\Seeders;

use PactTrackSDK\SharedResources\Modules\Notification\Models\NotificationType;
use PactTrackSDK\SharedResources\SDK\Database\MakeSeeder;

/**
 * Owns the notification catalogue — the rows behind `Notification::isset('key')`
 * and the `/notification` preferences screen.
 *
 * Same contract as `RolePermissionSeeder`: the CATALOGUE array below is the
 * source of truth, `notification_types` is a projection of it. Safe to run
 * repeatedly (`updateOrCreate` by `key`), and it prunes types no longer listed
 * here so a removed type can't linger and keep reading as enabled somewhere.
 * Pruning a type cascades away its `user_notifications` override rows.
 *
 * Wired into `Database\Seeders\DatabaseSeeder` so `php artisan migrate --seed`
 * (and `db:seed`) include it.
 *
 * Groups and copy are ported verbatim from Dashboard/Notifications.html. Only
 * Email is surfaced in the UI today; `default_in_app` / `default_sms` are kept
 * accurate to the artboard so those channels can ship without a data backfill.
 */
class NotificationTypeSeeder extends MakeSeeder
{
    private const GROUP_MATTERS = 'Matters & Documents';
    private const GROUP_MESSAGES = 'Messages';
    private const GROUP_BILLING = 'Account & Billing';

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogue(): array
    {
        return [
            [
                'key' => 'new_doc_uploaded',
                'label' => 'New document uploaded',
                'description' => 'When a client or teammate uploads a new file.',
                'group' => self::GROUP_MATTERS,
                'position' => 1,
                'default_email' => true,
                'default_in_app' => true,
                'default_sms' => false,
            ],
            [
                'key' => 'document_ready_for_signature',
                'label' => 'Document ready for signature',
                'description' => 'When an envelope is sent out for e-signature.',
                'group' => self::GROUP_MATTERS,
                'position' => 2,
                'default_email' => true,
                'default_in_app' => true,
                'default_sms' => false,
            ],
            [
                'key' => 'signature_completed',
                'label' => 'Signature completed',
                'description' => 'When all parties have signed a document.',
                'group' => self::GROUP_MATTERS,
                'position' => 3,
                'default_email' => true,
                'default_in_app' => true,
                'default_sms' => true,
            ],
            [
                'key' => 'milestone_updated',
                'label' => 'Milestone updated',
                'description' => "When a matter's status or milestone changes.",
                'group' => self::GROUP_MATTERS,
                'position' => 4,
                'default_email' => false,
                'default_in_app' => true,
                'default_sms' => false,
            ],
            [
                'key' => 'new_message_from_client',
                'label' => 'New message from a client',
                'description' => 'When a client sends you a direct message.',
                'group' => self::GROUP_MESSAGES,
                'position' => 1,
                'default_email' => true,
                'default_in_app' => true,
                'default_sms' => true,
            ],
            [
                'key' => 'unread_message_reminder',
                'label' => 'Unread message reminder',
                'description' => 'A daily nudge if a message sits unread past 24h.',
                'group' => self::GROUP_MESSAGES,
                'position' => 2,
                'default_email' => true,
                'default_in_app' => false,
                'default_sms' => false,
            ],
            [
                'key' => 'payment_received',
                'label' => 'Payment received',
                'description' => 'Confirmation when a client payment clears.',
                'group' => self::GROUP_BILLING,
                'position' => 1,
                'default_email' => true,
                'default_in_app' => true,
                'default_sms' => false,
            ],
            [
                'key' => 'invoice_overdue',
                'label' => 'Invoice overdue',
                'description' => 'When a sent invoice passes its due date.',
                'group' => self::GROUP_BILLING,
                'position' => 2,
                'default_email' => true,
                'default_in_app' => true,
                'default_sms' => false,
            ],
            [
                'key' => 'security_alerts',
                'label' => 'Security alerts',
                'description' => 'New sign-ins, password changes, and 2FA events.',
                'group' => self::GROUP_BILLING,
                'position' => 3,
                'default_email' => true,
                'default_in_app' => true,
                'default_sms' => false,
                // Security notifications can't be turned off. The screen renders
                // this as a "Required" lock instead of a toggle.
                'email_locked' => true,
                'in_app_locked' => true,
                'sms_locked' => true,
            ],
        ];
    }

    public function run(): void
    {
        $keys = [];

        foreach ($this->catalogue() as $entry) {
            $keys[] = $entry['key'];

            NotificationType::query()->updateOrCreate(
                ['key' => $entry['key']],
                [
                    'label' => $entry['label'],
                    'description' => $entry['description'],
                    'group' => $entry['group'],
                    'position' => $entry['position'],
                    'default_email' => $entry['default_email'],
                    'default_in_app' => $entry['default_in_app'],
                    'default_sms' => $entry['default_sms'],
                    'email_locked' => $entry['email_locked'] ?? false,
                    'in_app_locked' => $entry['in_app_locked'] ?? false,
                    'sms_locked' => $entry['sms_locked'] ?? false,
                ],
            );
        }

        // Converge on the catalogue — drop anything no longer listed.
        NotificationType::query()->whereNotIn('key', $keys)->delete();
    }

    public function revert(): void
    {
        NotificationType::query()
            ->whereIn('key', array_column($this->catalogue(), 'key'))
            ->delete();
    }
}
