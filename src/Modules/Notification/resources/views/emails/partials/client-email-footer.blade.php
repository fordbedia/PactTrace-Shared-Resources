{{--
    Client-facing email footer.

    Params:
      $providerName    — the tenant's business name (blank -> "Your Provider")
      $brandingEnabled — true (Professional/Firm) hides the PactTrack mark
      $poweredByFooter — accepted for call-site compatibility; ignored

    "Secured by PactTrack" shows on Starter only; Professional/Firm
    ($brandingEnabled) are fully white-labeled (2026-09-20).
--}}
@php($displayName = trim((string) ($providerName ?? '')) !== '' ? trim($providerName) : 'Your Provider')
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
    <tr>
        <td style="padding:0 24px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="text-align:center; padding:20px 16px; border-top:1px solid #E2E8F0;">
                        <p style="font-size:11px; color:#94A3B8; margin:0; line-height:1.6;">&copy; {{ date('Y') }} {{ $displayName }}. All rights reserved.</p>
                        @unless(!empty($brandingEnabled))
                        <p style="font-size:10px; color:#B8C2D0; margin:6px 0 0; line-height:1.5;">Secured by PactTrack</p>
                        @endunless
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
