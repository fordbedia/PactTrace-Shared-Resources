{{--
    Client-facing email footer.

    Params:
      $providerName    — the tenant's business name (blank -> "Your Provider")
      $brandingEnabled — accepted for call-site compatibility; no longer
                         changes the footer
      $poweredByFooter — accepted for call-site compatibility; ignored

    Every plan gets the provider's own copyright line PLUS a small,
    secondary "Secured by PactTrack" mark — PactTrack branding is not
    removable on any plan (2026-09-19 rule; supersedes the earlier
    "Professional/Firm are fully white-labeled" decision and the Email
    Branding "Powered by PactTrack" toggle).
--}}
@php($displayName = trim((string) ($providerName ?? '')) !== '' ? trim($providerName) : 'Your Provider')
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
    <tr>
        <td style="padding:0 24px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="text-align:center; padding:20px 16px; border-top:1px solid #E2E8F0;">
                        <p style="font-size:11px; color:#94A3B8; margin:0; line-height:1.6;">&copy; {{ date('Y') }} {{ $displayName }}. All rights reserved.</p>
                        <p style="font-size:10px; color:#B8C2D0; margin:6px 0 0; line-height:1.5;">Secured by PactTrack</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
