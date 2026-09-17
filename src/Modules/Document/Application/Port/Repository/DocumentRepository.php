<?php

namespace PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentFilters;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;

interface DocumentRepository
{
    public function create(array $data): Document;

    /**
     * How many non-archived documents a tenant has in the given lifecycle
     * states — backs the `/dashboard` "Docs Awaiting" card (`Sent` +
     * `PartiallySigned`, the same "awaiting signature" set the client portal's
     * status pills use). A `COUNT` in SQL, never a fetched collection.
     *
     * @param array<int, DocumentStatus> $statuses
     */
    public function countByStatusForProvider(int $providerId, array $statuses): int;

    /**
     * The tenant's most-recently-updated non-archived documents for the
     * `/dashboard` "Recent Documents" list, optionally narrowed to a set of
     * lifecycle states (the All / Pending / Signed / Draft filter pills —
     * the folding to real DocumentStatus values happens in the caller's DTO,
     * not here). Newest first, `uploader`/`matter`/`client`/`envelopes`
     * eager-loaded so DocumentResource renders without an N+1.
     *
     * @param array<int, DocumentStatus> $statuses empty = no status filter
     * @return Collection<int, Document>
     */
    public function recentForProvider(int $providerId, array $statuses, int $limit): Collection;

    /**
     * One page of every document belonging to a provider — "All Documents"
     * on /dashboard/documents — optionally narrowed to one client (a
     * client-role user must never see another client's documents; see
     * TenantScopedPolicy). Newest first, uploader eager-loaded so
     * DocumentResource can show who uploaded it without an N+1.
     *
     * `$archived` selects which of the two independent views the "Active" /
     * "Archived" tab on /dashboard/documents is asking for — false (default)
     * excludes anything with a non-null `archived_at`, true returns only
     * archived rows. This is unrelated to Document's SoftDeletes: a
     * soft-deleted row is already excluded by Eloquent's own deleted_at
     * global scope regardless of this flag, so the two filters never
     * interact. See .claude/rules/document.md, "Document Deletion &
     * Archival Rules".
     *
     * `$filters` narrows further — matter, client, file type, date range,
     * and free-text name search (the toolbar's filter chips + the top-bar
     * search box, both folder/tab-aware). See DocumentFilters and
     * .claude/rules/document.md, "File Type filter".
     *
     * @return LengthAwarePaginator<int, Document>
     */
    public function forProvider(int $providerId, DocumentFilters $filters, int $perPage, ?int $page, bool $archived = false): LengthAwarePaginator;

    /**
     * One page of the documents filed directly under any of the given folder
     * ids — ListDocumentsAction passes a folder plus every one of its
     * descendants, so focusing a parent folder shows files nested under it
     * at any depth, same as the folder tree itself.
     *
     * `$archived` — see forProvider() above; same independent archived/soft-delete
     * split applies here. `$filters` — see forProvider() above.
     *
     * @param array<int, int> $folderIds
     * @return LengthAwarePaginator<int, Document>
     */
    public function forFolders(int $providerId, array $folderIds, DocumentFilters $filters, int $perPage, ?int $page, bool $archived = false): LengthAwarePaginator;

    /**
     * One page of every document filed against a single matter — backs the
     * "Documents on this matter" section of the Matter Detail view on
     * `/dashboard/matters` (see .claude/rules/matter.md). `envelopes` is
     * eager-loaded (on top of the usual uploader/matter/client) so
     * `DocumentResource::envelope_public_id` can be derived without an
     * N+1 — that field is what the frontend uses to decide whether a row
     * gets a "View Signature" link.
     *
     * `$archived` — see forProvider() above; same independent
     * archived/soft-delete split applies here.
     *
     * @return LengthAwarePaginator<int, Document>
     */
    public function forMatter(int $providerId, int $matterId, ?int $clientId, int $perPage, ?int $page, bool $archived = false): LengthAwarePaginator;

    /**
     * Total bytes stored by a provider — `SUM(documents.size)`, optionally
     * narrowed to one client. Backs the STORAGE indicator on
     * /dashboard/documents through DocumentStorageUsageService.
     *
     * Aggregated in SQL rather than by summing a fetched collection: this is
     * called on every page load of the document centre, and a tenant with
     * thousands of documents must not have all of them hydrated into models
     * just to add up one column.
     */
    public function totalSizeForProvider(int $providerId, ?int $clientId = null): int;

    /**
     * Persists a change to an already-loaded Document (status transitions,
     * archived_at) — used by Delete/Archive/Unarchive/VoidDocumentHandler.
     * Named `save` rather than `update` to avoid colliding with
     * RepositoryLayer::update(array $data, ?int $id), which this class also
     * inherits but does not use here (it filters by a `user_id` column
     * `documents` doesn't have).
     */
    public function save(Document $document, array $attributes): Document;

    /**
     * Soft-deletes a Document. Callers must have already checked
     * DocumentDeletionPolicy — this method enforces nothing about status.
     */
    public function delete(Document $document): void;

    /**
     * Re-stamps `client_id` onto every document currently filed under a
     * matter, to the matter's own (just-changed) client — the cascade that
     * keeps a document's `client_id` from disagreeing with its matter's
     * *current* client once the matter itself is reassigned to a different
     * client (see .claude/rules/matter.md, "The Client field reassigns
     * which of the provider's clients owns the matter", and
     * .claude/rules/document.md, "Documents on this matter"). The matter's
     * `client_id` is the single source of truth for any document attached
     * to it; this is the one place that source of truth is pushed onto the
     * documents that denormalize it. Called from
     * Matter\Application\Action\UpdateMattersHandler inside the same
     * transaction as the matter's own client change — never as a
     * follow-up job. `acrossWorkspaces()` because a document's workspace
     * doesn't change here (only its matter's client does) and must not
     * gate which rows get corrected.
     *
     * @return int number of document rows updated
     */
    public function reassignClientForMatter(int $matterId, int $newClientId): int;
}
