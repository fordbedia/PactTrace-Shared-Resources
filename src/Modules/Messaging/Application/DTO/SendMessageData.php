<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * The input to SendMessageAction — one message posted from the New Message
 * modal (/dashboard/messages). Parsed once here so neither the action nor
 * the repository ever sees an Illuminate\Http\Request, matching the
 * Document module's DocumentData / DocumentListData.
 *
 * `client_id` is the source of truth for who the thread is with. When
 * `matter_id` is also given, the controller has already reconciled it
 * against the matter's own client (a Matter belongsTo exactly one Client —
 * see .claude/rules/matter.md) before constructing this, the same
 * "derive from the resolved parent, don't trust the request" rule the
 * Document module applies.
 *
 * @param list<UploadedFile> $attachments
 */
final readonly class SendMessageData
{
    /**
     * @param list<UploadedFile> $attachments
     */
    public function __construct(
        public int $provider_id,
        public int $sender_id,
        public int $client_id,
        public ?int $matter_id,
        public ?string $subject,
        public string $body,
        public array $attachments = [],
    ) {
    }

    /**
     * @param list<UploadedFile> $attachments
     */
    public static function fromRequest(
        FormRequest $request,
        int $provider_id,
        int $sender_id,
        int $client_id,
        ?int $matter_id,
        array $attachments,
    ): self {
        $subject = $request->input('subject');

        return new self(
            provider_id: $provider_id,
            sender_id: $sender_id,
            client_id: $client_id,
            matter_id: $matter_id,
            subject: is_string($subject) && trim($subject) !== '' ? trim($subject) : null,
            body: trim((string) $request->input('body')),
            attachments: array_values($attachments),
        );
    }
}
