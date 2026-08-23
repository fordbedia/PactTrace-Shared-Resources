<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the default milestone set (Engagement, Discovery, Drafting,
 * Review, Completed — see
 * Modules\Matter\Domain\ValueObjects\DefaultMilestone) onto every existing
 * Matter that has zero milestones. Application\Services\DefaultMilestoneSeeder
 * only seeds this set for a *newly created* Matter going forward
 * (CreateMattersHandler) — every Matter created before that change would
 * otherwise never render the client portal's Matter Progress timeline (see
 * .claude/rules/matter.md), since MatterDetailView only shows that card when
 * `matter.milestones.length > 0`.
 *
 * Plain query-builder inserts, not the Milestone Eloquent model — same style
 * as 2026_08_22_100000_add_public_id_to_matters_table's own backfill loop.
 */
return new class extends Migration
{
    private const DEFAULT_MILESTONE_NAMES = ['Engagement', 'Discovery', 'Drafting', 'Review', 'Completed'];

    public function up(): void
    {
        $matterIdsWithMilestones = DB::table('milestones')->select('matter_id')->distinct();

        $matterIds = DB::table('matters')
            ->whereNotIn('id', $matterIdsWithMilestones)
            ->pluck('id');

        $now = now();

        foreach ($matterIds as $matterId) {
            $rows = [];

            foreach (self::DEFAULT_MILESTONE_NAMES as $position => $name) {
                $rows[] = [
                    'matter_id' => $matterId,
                    'name' => $name,
                    'description' => null,
                    'status' => 'pending',
                    'position' => $position,
                    'due_date' => null,
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('milestones')->insert($rows);
        }
    }

    /**
     * No schema change to reverse, and no reliable way to tell a backfilled
     * milestone row apart from one a provider has since edited — this is a
     * one-way data backfill.
     */
    public function down(): void
    {
    }
};
