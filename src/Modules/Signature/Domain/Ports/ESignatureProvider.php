<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Signature\Domain\Ports;

/**
 * Outbound port to whatever e-signature provider is configured
 * (Documenso today; HelloSign/DocuSign are swappable adapters behind this
 * same interface per the hexagonal rule in the top-level CLAUDE.md).
 *
 * Deliberately narrow: PactTrace only uses this port to get a document into
 * the provider and hand a human a link to the provider's own native
 * prepare/tag UI. Recipient and field-placement APIs are intentionally NOT
 * wrapped here — the attorney tags fields and adds recipients directly in
 * the provider's own dashboard (see Signature module rules doc, "field
 * placement" section), so PactTrace's domain code never needs to model
 * field coordinates itself. If that decision changes (e.g. PactTrace builds
 * its own coordinate-based tagger), extend this port with
 * createRecipients()/createFields() methods rather than reaching for the
 * provider's SDK/HTTP client outside of Infrastructure/.
 */
interface ESignatureProvider
{
    /**
     * Create a draft envelope in the provider and upload the document's
     * bytes to it. Returns the provider's own envelope/document identifier
     * (persisted locally as Envelope::provider_envelope_id).
     */
    public function createDraftEnvelope(
        string $title,
        string $fileName,
        string $fileContents,
        ?string $externalId = null,
    ): string;

    /**
     * Build a URL to the provider's own hosted "prepare" UI for this
     * envelope — where a human drags signature/date/text fields onto the
     * document and adds recipients, using the provider's native editor.
     * Not embedded in PactTrace; opened in a new tab/window.
     */
    public function editorUrl(string $providerEnvelopeId): string;
}
