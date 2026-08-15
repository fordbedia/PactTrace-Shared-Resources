<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're invited to your client portal</title>
</head>
<body style="margin:0; padding:40px 16px; background:#E8EAF0; font-family:'Inter',Arial,sans-serif;">

<div style="max-width:600px; margin:0 auto;">
    <div style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(15,23,42,0.08); border:1px solid #E2E8F0;">

        <!-- Header Band -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#10162B;">
            <tr>
                <td style="padding:24px 32px;">
                    <table cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="vertical-align:middle; padding-right:10px;">
                                <svg width="28" height="28" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <defs><clipPath id="fc-invite"><path d="M3 13 C3 9.686 5.686 7 9 7 H15.5 L20.5 13 H31 C34.314 13 37 15.686 37 19 V28 C37 31.314 34.314 34 31 34 H9 C5.686 34 3 31.314 3 28 Z"/></clipPath></defs>
                                    <path d="M3 13 C3 9.686 5.686 7 9 7 H15.5 L20.5 13 H31 C34.314 13 37 15.686 37 19 V28 C37 31.314 34.314 34 31 34 H9 C5.686 34 3 31.314 3 28 Z" fill="#F8FAFC"/>
                                    <g clip-path="url(#fc-invite)"><path d="M3 26 H19 V20 H37" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="butt" fill="none"/></g>
                                    <circle cx="25" cy="20" r="2.5" fill="#FCD34D"/>
                                </svg>
                            </td>
                            <td style="vertical-align:middle;">
                                <span style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:-0.03em; font-family:'Inter',Arial,sans-serif;">PactTrace</span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Canvas -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
            <tr>
                <td style="padding:32px 24px;">
                    <table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; border:1px solid #E2E8F0; overflow:hidden;">
                        <tr>
                            <!-- Amber top accent bar -->
                            <td style="background:#D97706; height:4px; padding:0; line-height:0; font-size:0;">&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="padding:36px 36px 28px;">

                                <!-- Icon -->
                                <div style="width:48px; height:48px; background:#FEF3C7; border-radius:12px; display:flex; align-items:center; justify-content:center; margin-bottom:20px; text-align:center; line-height:48px;">
                                    <span style="color:#D97706; font-size:20px; font-weight:700;">&#128100;+</span>
                                </div>

                                <!-- Headline -->
                                <h1 style="font-size:22px; font-weight:700; color:#0F172A; letter-spacing:-0.02em; margin:0 0 12px; line-height:1.3;">
                                    @if($clientName)You're invited, {{ $clientName }} @else You're invited to your client portal @endif
                                </h1>

                                <!-- Body copy -->
                                <p style="font-size:14px; color:#334155; line-height:1.7; margin:0 0 24px; max-width:460px;">
                                    <strong style="color:#0F172A;">{{ $invitedByName }}</strong> at <strong style="color:#0F172A;">{{ $providerName }}</strong> has invited you to a secure PactTrace portal, where you can view documents, track progress, and sign agreements online — all in one place.
                                </p>

                                <!-- Detail box -->
                                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:16px 20px; margin:0 0 24px;">
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="padding-bottom:10px; border-bottom:1px solid #E2E8F0;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="font-size:13px; color:#64748B;">Invited by</td>
                                                        <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $invitedByName }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding-top:10px;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="font-size:13px; color:#64748B;">Firm</td>
                                                        <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $providerName }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </div>

                                <!-- CTA -->
                                <div style="text-align:center; margin:28px 0 16px;">
                                    <a href="{{ $acceptUrl }}" style="display:inline-block; background-color:#D97706; color:#ffffff; font-weight:700; font-size:15px; letter-spacing:-0.01em; text-decoration:none; padding:13px 32px; border-radius:8px; text-align:center;">
                                        Accept Invitation
                                    </a>
                                </div>

                                <!-- Raw link fallback -->
                                <p style="font-size:11px; color:#94A3B8; text-align:center; margin-top:8px; word-break:break-all;">
                                    If the button above doesn't work, copy and paste this link into your browser:<br>
                                    <a href="{{ $acceptUrl }}" style="color:#B45309;">{{ $acceptUrl }}</a>
                                </p>

                                <hr style="border:none; border-top:1px solid #E2E8F0; margin:28px 0 20px;">

                                <p style="font-size:12px; color:#94A3B8; line-height:1.6; margin:0;">
                                    This invitation was sent to <strong style="color:#64748B;">{{ $email }}</strong>. If you weren't expecting this, you can safely ignore this email.
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
                                <p style="font-size:13px; font-weight:700; color:#64748B; letter-spacing:-0.01em; margin:0 0 6px;">PactTrace</p>
                                <p style="font-size:11px; color:#94A3B8; margin:0 0 8px; line-height:1.6;">Secure client portal for solo service professionals &middot; &copy; {{ date('Y') }} PactTrace, Inc.</p>
                                <p style="font-size:11px; color:#94A3B8; margin:0 0 8px;">548 Market St, PMB 12345, San Francisco, CA 94104</p>
                                <p style="font-size:11px; margin:0;">
                                    <a href="{{ $preferencesUrl ?? '#' }}" style="color:#94A3B8; text-decoration:underline; font-size:11px; margin-right:12px;">Notification Preferences</a>
                                    <a href="{{ $unsubscribeUrl ?? '#' }}" style="color:#94A3B8; text-decoration:underline; font-size:11px;">Unsubscribe</a>
                                </p>
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
