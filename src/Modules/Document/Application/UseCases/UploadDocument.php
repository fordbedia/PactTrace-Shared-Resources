<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Application\UseCases;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;

/**
 * Use case behind the "Upload Documents" modal on /dashboard/documents.
 *
 * Deliberately creates a new Document row every time rather than detecting
 * "this is a new version of an existing file" — that's real product logic
 * (match on name + matter? let the user pick explicitly?) that hasn't been
 * decided yet. DocumentVersion exists in the schema for when it is; this
 * use case is the natural place to add that branch later.
 */
class UploadDocument
{
    public function __construct(
        private readonly DocumentStorage $storage,
    ) {
    }

    public function handle(
        UploadedFile $file,
        int $providerId,
        int $uploadedBy,
        ?int $matterId = null,
        ?int $clientId = null,
        ?int $folderId = null,
    ): Document {
        $path = sprintf(
            'documents/%d/%s-%s',
            $providerId,
            (string) Str::uuid(),
            $file->getClientOriginalName(),
        );

        $this->storage->put($path, (string) file_get_contents($file->getRealPath()));

        $document = new Document([
            'provider_id' => $providerId,
            'client_id' => $clientId,
            'matter_id' => $matterId,
            'folder_id' => $folderId,
            'uploaded_by' => $uploadedBy,
            'name' => $file->getClientOriginalName(),
            's3_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'version' => 1,
        ]);
        $document->save();

        return $document;
    }
}
