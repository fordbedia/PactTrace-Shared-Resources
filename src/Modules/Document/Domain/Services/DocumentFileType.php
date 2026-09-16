<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Domain\Services;

/**
 * Buckets a filename's extension into one of the 8 groups the File Type
 * filter on /dashboard/documents offers — see .claude/rules/document.md,
 * "File Type filter". This is the server-side twin of the frontend's own
 * `extToType()` (frontend/app/dashboard/documents/shared.js): the two are
 * independently maintained (the frontend needs a bucket before a file is
 * even uploaded, so it can't just read `documents.file_type` off a row that
 * doesn't exist yet) but MUST agree bucket-for-bucket, or a document's
 * server-side `file_type` and its client-side badge would disagree.
 *
 * Framework-free per the hexagonal rule in the top-level CLAUDE.md — pure
 * string logic, no Illuminate\* imports.
 */
final class DocumentFileType
{
    public const PDF = 'pdf';
    public const DOC = 'doc';
    public const HTML = 'html';
    public const MSG = 'msg';
    public const RTF = 'rtf';
    public const TXT = 'txt';
    public const WPD = 'wpd';
    public const XPS = 'xps';

    /** @return list<string> every bucket key, in the same order the frontend's FILE_TYPES map lists them. */
    public static function values(): array
    {
        return [self::PDF, self::DOC, self::HTML, self::MSG, self::RTF, self::TXT, self::WPD, self::XPS];
    }

    public static function fromFileName(string $fileName): string
    {
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

        return match (true) {
            $extension === 'pdf' => self::PDF,
            in_array($extension, ['doc', 'docm', 'docx', 'dot', 'dotm', 'dotx'], true) => self::DOC,
            in_array($extension, ['htm', 'html', 'xhtml'], true) => self::HTML,
            $extension === 'msg' => self::MSG,
            $extension === 'rtf' => self::RTF,
            $extension === 'txt' => self::TXT,
            $extension === 'wpd' => self::WPD,
            $extension === 'xps' => self::XPS,
            default => self::DOC,
        };
    }
}
