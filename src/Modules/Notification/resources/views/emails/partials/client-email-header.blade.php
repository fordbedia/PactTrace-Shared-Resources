{{--
    Client-facing email header band (the dark #10162B strip at the top).

    Params:
      $providerName    — the tenant's business name (blank falls back to
                         "Your Provider" — never crashes on null)
      $logoUrl         — the tenant's logo URL, or null
      $brandingEnabled — Plan::info()->allowsCustomBranding for this tenant
                         (false on Starter, true on Professional/Firm)

    Same rule as User\Domain\ValueObjects\ProviderBrand (the one place it is
    defined): Starter shows the business name as text only, never a logo;
    Professional/Firm show the uploaded logo, falling back to the name when
    none is uploaded. PactTrack's own mark lives in the footer partial on
    every plan, not here.
--}}
@php($displayName = trim((string) ($providerName ?? '')) !== '' ? trim($providerName) : 'Your Provider')
<table width="100%" cellpadding="0" cellspacing="0" style="background:#10162B;">
    <tr>
        <td style="padding:24px 32px;">
            <table cellpadding="0" cellspacing="0">
                <tr>
                    @if($brandingEnabled && !empty($logoUrl))
                        <td style="vertical-align:middle;">
                            <img src="{{ $logoUrl }}" alt="{{ $displayName }}" height="28" style="display:block; height:28px; max-width:160px; object-fit:contain;">
                        </td>
                    @else
                        <td style="vertical-align:middle;">
                            <span style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:-0.03em; font-family:'Inter',Arial,sans-serif;">{{ $displayName }}</span>
                        </td>
                    @endif
                </tr>
            </table>
        </td>
    </tr>
</table>
