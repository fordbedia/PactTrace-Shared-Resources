<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\Action;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use RuntimeException;
use ZipArchive;

/**
 * The Documents page's bulk "Zip" action — packages every selected document
 * into one .zip on a local temp file (goes through {@see DocumentStorage}
 * for every file's bytes, same port {@see DownloadDocumentAction} uses, so
 * this never talks to S3/the disk directly — see the port's own docblock).
 * The controller streams the resulting file back and deletes it once the
 * response finishes.
 *
 * Duplicate file names (two documents in different folders sharing a name)
 * are disambiguated by suffixing " (2)", " (3)", … — silently overwriting
 * one file with another inside the same zip would lose data with no
 * indication to the user.
 */
class BuildDocumentsZipAction
{
    public function __construct(private readonly DocumentStorage $storage)
    {
    }

    /**
     * @param Collection<int, Document> $documents
     * @return string absolute path to the built zip (caller's responsibility
     *                to delete once it's done streaming)
     */
    public function handle(Collection $documents, User $actor): string
    {
        // tempnam() both reserves the name AND creates the file, so appending
        // ".zip" to its return value would leave the original zero-byte file
        // behind on every single bulk-zip request, with nothing to ever clean
        // it up (the controller's deleteFileAfterSend only knows about the
        // path returned below). Use the reserved path itself — ZipArchive is
        // happy to write into an existing empty file with OVERWRITE.
        $zipPath = tempnam(sys_get_temp_dir(), 'pacttrack-docs-');

        if ($zipPath === false) {
            throw new RuntimeException('Could not allocate a temporary file for the zip archive.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip archive.');
        }

        $usedNames = [];

        foreach ($documents as $document) {
            $zip->addFromString($this->uniqueName($document->name, $usedNames), $this->storage->get($document->s3_path));

            AuditLog::create([
                'provider_id' => $document->provider_id,
                'user_id' => $actor->id,
                'action' => 'document.downloaded',
                'auditable_type' => Document::class,
                'auditable_id' => $document->id,
                'metadata' => ['via' => 'bulk_zip'],
            ]);
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * @param array<string, int> $usedNames mutated in place — tracks how many
     *        times each base name has been seen so far in this zip.
     */
    private function uniqueName(string $name, array &$usedNames): string
    {
        if (! isset($usedNames[$name])) {
            $usedNames[$name] = 1;

            return $name;
        }

        $usedNames[$name]++;
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension !== '' ? substr($name, 0, -(strlen($extension) + 1)) : $name;

        return $extension !== ''
            ? sprintf('%s (%d).%s', $base, $usedNames[$name], $extension)
            : sprintf('%s (%d)', $base, $usedNames[$name]);
    }
}
