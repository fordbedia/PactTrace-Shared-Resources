<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Tests;

use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\AuditLogListData;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Unit coverage for {@see AuditLogListData::fromRequest()} — the query-string
 * parsing `AuditLogController::index()` hands to
 * {@see \PactTrackSDK\SharedResources\Modules\Notification\Application\Action\ListAuditLogsHandler}.
 * No DB, no HTTP routing — a plain `Illuminate\Http\Request` in, the DTO's own
 * fields out. AuditLogControllerTest exercises the same parsing indirectly
 * through real query strings (actions=, per_page=, from=/to=); this file
 * isolates the edge cases fromRequest()/normalizeDate() have to handle on
 * their own (comma-splitting, whitespace, out-of-range per_page, unparsable
 * dates) without a full request/response round trip.
 */
class AuditLogListDataTest extends BaseTest
{
    private function request(array $query = []): Request
    {
        return Request::create('/api/v1/audit-logs', 'GET', $query);
    }

    public function test_provider_id_comes_from_the_argument_not_the_query_string(): void
    {
        $data = AuditLogListData::fromRequest($this->request(['provider_id' => 999]), 7);

        $this->assertSame(7, $data->provider_id);
    }

    public function test_per_page_defaults_to_20(): void
    {
        $data = AuditLogListData::fromRequest($this->request(), 1);

        $this->assertSame(20, $data->per_page);
    }

    public function test_per_page_is_clamped_between_1_and_100(): void
    {
        $this->assertSame(100, AuditLogListData::fromRequest($this->request(['per_page' => 100000]), 1)->per_page);
        $this->assertSame(1, AuditLogListData::fromRequest($this->request(['per_page' => 0]), 1)->per_page);
        $this->assertSame(1, AuditLogListData::fromRequest($this->request(['per_page' => -5]), 1)->per_page);
        $this->assertSame(50, AuditLogListData::fromRequest($this->request(['per_page' => 50]), 1)->per_page);
    }

    public function test_page_is_null_when_absent_and_cast_to_int_when_present(): void
    {
        $this->assertNull(AuditLogListData::fromRequest($this->request(), 1)->page);
        $this->assertSame(3, AuditLogListData::fromRequest($this->request(['page' => '3']), 1)->page);
    }

    public function test_actions_accepts_a_comma_separated_string_and_trims_each_entry(): void
    {
        $data = AuditLogListData::fromRequest(
            $this->request(['actions' => 'document.archived, envelope.sent ,, ']),
            1,
        );

        $this->assertSame(['document.archived', 'envelope.sent'], $data->actions);
    }

    public function test_actions_accepts_a_native_array(): void
    {
        $data = AuditLogListData::fromRequest(
            $this->request(['actions' => ['document.archived', ' envelope.sent ']]),
            1,
        );

        $this->assertSame(['document.archived', 'envelope.sent'], $data->actions);
    }

    public function test_actions_defaults_to_an_empty_array(): void
    {
        $this->assertSame([], AuditLogListData::fromRequest($this->request(), 1)->actions);
        $this->assertSame([], AuditLogListData::fromRequest($this->request(['actions' => '']), 1)->actions);
    }

    public function test_search_is_trimmed_and_blank_becomes_null(): void
    {
        $this->assertSame(
            'Rachel',
            AuditLogListData::fromRequest($this->request(['search' => '  Rachel  ']), 1)->search,
        );
        $this->assertNull(AuditLogListData::fromRequest($this->request(['search' => '   ']), 1)->search);
        $this->assertNull(AuditLogListData::fromRequest($this->request(), 1)->search);
    }

    public function test_from_and_to_normalize_to_y_m_d(): void
    {
        $data = AuditLogListData::fromRequest(
            $this->request(['from' => '2026-01-05T10:22:00Z', 'to' => 'March 3, 2026']),
            1,
        );

        $this->assertSame('2026-01-05', $data->from);
        $this->assertSame('2026-03-03', $data->to);
    }

    public function test_from_and_to_fall_back_to_null_on_an_unparsable_date(): void
    {
        $data = AuditLogListData::fromRequest(
            $this->request(['from' => 'not-a-date', 'to' => '']),
            1,
        );

        $this->assertNull($data->from);
        $this->assertNull($data->to);
    }
}
