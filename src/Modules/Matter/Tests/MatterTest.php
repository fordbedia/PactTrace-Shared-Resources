<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * `Matter::public_id`, added so the client portal never exposes the
 * enumerable auto-increment `id` in a URL — see .claude/rules/matter.md
 * and the migration's own docblock. Mirrors
 * Signature\Tests\EnvelopeControllerTest::test_public_id_is_auto_populated_and_unique.
 */
class MatterTest extends BaseTest
{
    public function test_public_id_is_auto_populated_and_unique(): void
    {
        $one = Matter::factory()->create();
        $two = Matter::factory()->create();

        $this->assertNotEmpty($one->public_id);
        $this->assertNotSame($one->public_id, $two->public_id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $one->public_id);
    }

    public function test_route_key_name_is_public_id(): void
    {
        $matter = Matter::factory()->create();

        $this->assertSame('public_id', $matter->getRouteKeyName());
        $this->assertSame($matter->public_id, $matter->getRouteKey());
    }
}
