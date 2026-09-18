<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Discovery" is removed from the default milestone set
 * (Modules\Matter\Domain\ValueObjects\DefaultMilestone::ordered()) — it had
 * no real action anywhere in the product that ever advanced it past
 * `pending` (confirmed: MilestoneProgressionService never completes it, and
 * no staff/client-facing control exists to mark it done by hand), so every
 * matter's progress was silently capped below 100% by a checklist item
 * nothing could ever check off. See .claude/rules/matter.md, "Matter
 * Progress timeline".
 *
 * This removes the already-existing "Discovery" `milestones` rows outright
 * (not marked completed and left in place) so an old matter's milestone list
 * matches the new 4-step template exactly — a stray, permanently-completed
 * "Discovery" row would otherwise be cosmetically inconsistent with every
 * matter created after this migration, for no benefit: the row carries no
 * information `audit_logs` doesn't already capture, and `Milestone` is a
 * live checklist, not an audit trail (see .claude/rules/notification.md for
 * where the real audit trail lives).
 *
 * Plain query-builder delete, same style as
 * 2026_08_22_110000_backfill_default_milestones_for_matters's own
 * query-builder backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('milestones')->where('name', 'Discovery')->delete();
    }

    /**
     * One-way, same as the backfill migration this one cleans up after —
     * there's no reliable way to tell which matters legitimately never had a
     * "Discovery" row (created after this migration) from ones that did and
     * had it removed.
     */
    public function down(): void
    {
    }
};
