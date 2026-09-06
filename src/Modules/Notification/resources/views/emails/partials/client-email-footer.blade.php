{{--
    Client-facing email footer.

    Params:
      $providerName    — the tenant's business name
      $brandingEnabled — Plan::info()->allowsCustomBranding for this tenant

    White-labeled (Professional/Firm) tenants get ONLY their own copyright
    line — no PactTrack mark, name, or tagline anywhere in the email. Every
    other tenant gets the standard PactTrack footer. See
    .claude/rules/notification.md, "Client-facing vs. internal email branding".
--}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
    <tr>
        <td style="padding:0 24px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="text-align:center; padding:20px 16px; border-top:1px solid #E2E8F0;">
                        @if($brandingEnabled)
                            <p style="font-size:11px; color:#94A3B8; margin:0; line-height:1.6;">&copy; {{ date('Y') }} {{ $providerName }}. All rights reserved.</p>
                        @else
                            <p style="font-size:13px; font-weight:700; color:#64748B; letter-spacing:-0.01em; margin:0 0 6px;">PactTrack</p>
                            <p style="font-size:11px; color:#94A3B8; margin:0; line-height:1.6;">Secure client portal for solo service professionals &middot; &copy; {{ date('Y') }} PactTrack, Inc.</p>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
