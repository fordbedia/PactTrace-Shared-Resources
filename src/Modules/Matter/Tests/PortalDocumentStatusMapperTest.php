<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\PortalDocumentStatusMapper;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

class PortalDocumentStatusMapperTest extends BaseTest
{
    public function test_it_maps_every_document_status_to_a_portal_pill(): void
    {
        $this->assertSame(['key' => 'draft', 'label' => 'Draft'], PortalDocumentStatusMapper::map(DocumentStatus::Draft));
        $this->assertSame(['key' => 'awaiting', 'label' => 'Awaiting signature'], PortalDocumentStatusMapper::map(DocumentStatus::Sent));
        $this->assertSame(['key' => 'partially_signed', 'label' => 'Partially signed'], PortalDocumentStatusMapper::map(DocumentStatus::PartiallySigned));
        $this->assertSame(['key' => 'signed', 'label' => 'Signed'], PortalDocumentStatusMapper::map(DocumentStatus::Completed));
        $this->assertSame(['key' => 'voided', 'label' => 'Voided'], PortalDocumentStatusMapper::map(DocumentStatus::Voided));
    }

    public function test_every_enum_case_is_covered(): void
    {
        foreach (DocumentStatus::cases() as $case) {
            $mapped = PortalDocumentStatusMapper::map($case);
            $this->assertArrayHasKey('key', $mapped);
            $this->assertArrayHasKey('label', $mapped);
            $this->assertNotSame('', $mapped['key']);
            $this->assertNotSame('', $mapped['label']);
        }
    }
}
