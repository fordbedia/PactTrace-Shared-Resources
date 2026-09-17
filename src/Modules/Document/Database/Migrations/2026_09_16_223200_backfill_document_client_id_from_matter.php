<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-way data repair for the bug fixed alongside this migration — see
 * .claude/rules/document.md, "Resolved bug (2026-09-16)" under "Documents on
 * this matter", and .claude/rules/matter.md, "Matter Type and Edit Matter".
 *
 * Before this fix, reassigning a Matter's own client (EditMatterModal's
 * Client field, `PATCH /matters/{public_id}`) never cascaded onto documents
 * already filed under that matter, so `documents.client_id` could silently
 * disagree with its own matter's *current* client indefinitely — confirmed
 * live for Matter `01M2PHXV1MCJY7QVXH95X2MFX3` ("Snow Home"), reassigned
 * from Stacey Mckinley to Jon Snow, whose documents kept reading the old
 * client. `UpdateMattersHandler` now keeps this in sync going forward
 * (DocumentRepository::reassignClientForMatter, in the same DB transaction
 * as the matter's own client update); this migration is the one-time
 * correction for rows that already drifted before that fix existed.
 *
 * Plain query-builder update, not the Eloquent models — same style as
 * 2026_08_22_110000_backfill_default_milestones_for_matters. Scoped to
 * documents that have a matter (a matterless document has no matter client
 * to derive from and is untouched, same as the live write path).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('documents')
            ->join('matters', 'matters.id', '=', 'documents.matter_id')
            ->whereNotNull('documents.matter_id')
            ->whereColumn('documents.client_id', '!=', 'matters.client_id')
            ->update(['documents.client_id' => DB::raw('matters.client_id')]);
    }

    /**
     * No schema change to reverse, and no record of what each document's
     * `client_id` was before this correction — a one-way data backfill,
     * same as the migration it mirrors.
     */
    public function down(): void
    {
    }
};
