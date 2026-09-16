<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Application\DTO;

/**
 * The additional narrowing conditions applied on top of a folder scope on
 * /dashboard/documents (see .claude/rules/document.md, "File Type filter" /
 * "Matter and Client filters") — Matter, Client, File Type and Date Range.
 *
 * Introduced so `DocumentRepository::forProvider()`/`forFolders()` don't grow
 * another positional parameter every time a new filter chip is added; both
 * accept one `DocumentFilters` instead. Deliberately NOT used by
 * `forMatter()` — that method (and its one caller, the Matter Detail page's
 * "Documents on this matter" section) has no folder concept at all and is
 * left untouched, per .claude/rules/document.md.
 */
final readonly class DocumentFilters
{
    /** @param list<string> $fileTypes */
    public function __construct(
        public ?int $matterId = null,
        public ?int $clientId = null,
        public array $fileTypes = [],
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $search = null,
    ) {
    }

    /**
     * A client-portal user's own client_id always overrides whatever the
     * request carried — resolved by the caller (ListDocumentsAction), not
     * here, since it depends on the acting User.
     */
    public function withClientId(?int $clientId): self
    {
        return new self($this->matterId, $clientId, $this->fileTypes, $this->dateFrom, $this->dateTo, $this->search);
    }

    /** True when none of the folder-scoped narrowing fields are set — used to
     * decide whether a bare `matter_id` should still route to the legacy,
     * folder-agnostic `forMatter()` path (see ListDocumentsAction). */
    public function isEmpty(): bool
    {
        return $this->matterId === null
            && $this->clientId === null
            && $this->fileTypes === []
            && $this->dateFrom === null
            && $this->dateTo === null
            && ($this->search === null || $this->search === '');
    }
}
