<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\DocusignReturnUrls;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

class DocusignReturnUrlsTest extends BaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_every_url_derives_from_the_configured_return_base_url_not_the_frontend_url(): void
    {
        config([
            'app.frontend_url' => 'http://localhost:3000',
            'services.docusign.return_base_url' => 'https://tunnel.example.com/',
        ]);
        Log::spy();

        $urls = app(DocusignReturnUrls::class);
        $envelope = new Envelope(['public_id' => 'ENV123']);
        $envelope->public_id = 'ENV123';

        $this->assertSame('https://tunnel.example.com/docusign-return?flow=sender&envelope=ENV123', $urls->sender($envelope));
        $this->assertSame('https://tunnel.example.com/docusign-return?flow=recipient&envelope=ENV123', $urls->recipient($envelope));
        $this->assertSame('https://tunnel.example.com/docusign-return', $urls->consentRedirect());
    }

    public function test_it_falls_back_to_the_frontend_url_when_no_return_base_url_is_set(): void
    {
        config(['app.frontend_url' => 'https://app.example.com', 'services.docusign.return_base_url' => null]);
        Log::spy();

        $this->assertSame('https://app.example.com/docusign-return', app(DocusignReturnUrls::class)->consentRedirect());
    }

    public function test_it_warns_when_the_host_is_loopback_or_local_only(): void
    {
        Log::spy();

        foreach (['http://127.0.0.1:8000', 'http://localhost:3000', 'https://portal.test'] as $base) {
            Cache::flush();
            config(['services.docusign.return_base_url' => $base]);

            app(DocusignReturnUrls::class)->consentRedirect();
        }

        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_it_warns_once_per_host_not_on_every_call(): void
    {
        config(['services.docusign.return_base_url' => 'http://localhost:3000']);
        Log::spy();

        app(DocusignReturnUrls::class)->consentRedirect();
        app(DocusignReturnUrls::class)->consentRedirect();

        Log::shouldHaveReceived('warning')->once();
    }
}
