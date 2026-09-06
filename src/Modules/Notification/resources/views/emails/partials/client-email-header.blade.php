{{--
    Client-facing email header band (the dark #10162B strip at the top).

    Params:
      $providerName    — the tenant's business name
      $logoUrl         — the tenant's logo URL, or null
      $brandingEnabled — Plan::info()->allowsCustomBranding for this tenant
                         (false on Starter, true on Professional/Firm)

    White-labeled (Professional/Firm) tenants get their own logo + name; every
    other tenant gets the PactTrack signature wordmark. Same gate as
    PortalShell / /dashboard/branding — see .claude/rules/notification.md,
    "Client-facing vs. internal email branding".
--}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#10162B;">
    <tr>
        <td style="padding:24px 32px;">
            <table cellpadding="0" cellspacing="0">
                <tr>
                    @if($brandingEnabled && !empty($logoUrl))
                        <td style="vertical-align:middle; padding-right:10px;">
                            <img src="{{ $logoUrl }}" alt="{{ $providerName }}" height="28" style="display:block; height:28px; max-width:160px; object-fit:contain;">
                        </td>
                        <td style="vertical-align:middle;">
                            <span style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:-0.03em; font-family:'Inter',Arial,sans-serif;">{{ $providerName }}</span>
                        </td>
                    @elseif($brandingEnabled)
                        <td style="vertical-align:middle;">
                            <span style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:-0.03em; font-family:'Inter',Arial,sans-serif;">{{ $providerName }}</span>
                        </td>
                    @else
                        <td style="vertical-align:middle; padding-right:10px;">
                            <svg width="28" height="28" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4 22C4 22 7 12 11 12C13.5 12 13.5 17 16 17C18 17 18 12 20 12" stroke="#F1F5F9" stroke-width="2.5" stroke-linecap="round" fill="none"/>
                                <circle cx="23" cy="12" r="1.4" fill="#FBBF24"/>
                                <circle cx="26.5" cy="12" r="1.4" fill="#FBBF24"/>
                            </svg>
                        </td>
                        <td style="vertical-align:middle;">
                            <span style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:-0.03em; font-family:'Inter',Arial,sans-serif;">PactTrack</span>
                        </td>
                    @endif
                </tr>
            </table>
        </td>
    </tr>
</table>
