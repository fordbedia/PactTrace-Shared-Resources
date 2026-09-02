{{--
    Shared "Variant D — Generic System Notification" shell
    (Dashboard/PactTrack-Email-template.html) for every internal
    (owner/admin/staff) notification email. PactTrack-branded on purpose —
    never the provider's branding — the audience is the provider's own team
    in software they know is PactTrack. See
    .claude/rules/notification.md, "Notification::isset() gating at dispatch
    sites", and StaffUnreadMessageReminderEmail (the first email of this
    kind, which predates this layout and stays standalone).

    A child view supplies:
      @extends('notification::emails.layouts.system', ['title' => '...'])
      @section('body')      the icon chip + heading + copy + detail rows + CTA
      @section('footnote')  one line explaining why this email was sent (optional)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'PactTrack notification' }}</title>
</head>
<body style="margin:0; padding:40px 16px; background:#E8EAF0; font-family:'Inter',Arial,sans-serif;">

<div style="max-width:600px; margin:0 auto;">
    <div style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(15,23,42,0.08); border:1px solid #E2E8F0;">

        <!-- Header Band — PactTrack's own branding, never the provider's -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#10162B;">
            <tr>
                <td style="padding:24px 32px;">
                    <span style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:-0.03em; font-family:'Inter',Arial,sans-serif;">PactTrack</span>
                </td>
            </tr>
        </table>

        <!-- Canvas -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
            <tr>
                <td style="padding:32px 24px;">
                    <table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; border:1px solid #E2E8F0; overflow:hidden;">
                        <tr>
                            <td style="background:#D97706; height:4px; padding:0; line-height:0; font-size:0;">&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="padding:36px 36px 28px;">

                                @yield('body')

                                <hr style="border:none; border-top:1px solid #E2E8F0; margin:28px 0 20px;">

                                <p style="font-size:11px; color:#94A3B8; line-height:1.6; margin:0;">
                                    @yield('footnote', 'You&rsquo;re receiving this because of your PactTrack notification settings. You can change what PactTrack emails you from Notification Preferences.')
                                </p>

                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Footer -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
            <tr>
                <td style="padding:0 24px 32px;">
                    <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="text-align:center; padding:20px 16px; border-top:1px solid #E2E8F0;">
                                <p style="font-size:13px; font-weight:700; color:#64748B; letter-spacing:-0.01em; margin:0 0 6px;">PactTrack</p>
                                <p style="font-size:11px; color:#94A3B8; margin:0; line-height:1.6;">Secure client portal for solo service professionals &middot; &copy; {{ date('Y') }} PactTrack, Inc.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

    </div>
</div>

</body>
</html>
