<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New message from {{ $clientName }}</title>
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

                                <!-- Icon chip -->
                                <table cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                                    <tr>
                                        <td style="width:48px; height:48px; background:#FEF3C7; border-radius:12px; text-align:center; vertical-align:middle;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M4 6.5C4 5.67 4.67 5 5.5 5H18.5C19.33 5 20 5.67 20 6.5V17.5C20 18.33 19.33 19 18.5 19H5.5C4.67 19 4 18.33 4 17.5V6.5Z" stroke="#D97706" stroke-width="2" fill="none"/>
                                                <path d="M5 7L12 12.5L19 7" stroke="#D97706" stroke-width="2" stroke-linecap="round" fill="none"/>
                                            </svg>
                                        </td>
                                    </tr>
                                </table>

                                <h1 style="font-size:22px; font-weight:700; color:#0F172A; letter-spacing:-0.02em; margin:0 0 12px; line-height:1.3;">
                                    New message from {{ $clientName }}
                                </h1>

                                <p style="font-size:14px; color:#334155; line-height:1.7; margin:0 0 20px; max-width:460px;">
                                    Hi {{ $staffName }}, <strong style="color:#0F172A;">{{ $clientName }}</strong> sent you a message that&rsquo;s been waiting for a few minutes. Here&rsquo;s a preview &mdash; open PactTrack to read it in full and reply.
                                </p>

                                <!-- Detail row -->
                                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:14px 18px; margin:20px 0;">
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="padding-bottom:8px; border-bottom:1px solid #E2E8F0;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="font-size:12px; color:#64748B; font-weight:500;">Client</td>
                                                        <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $clientName }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        @if (!empty($matterName))
                                        <tr>
                                            <td style="padding-top:8px; padding-bottom:8px; border-bottom:1px solid #E2E8F0;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="font-size:12px; color:#64748B; font-weight:500;">Matter</td>
                                                        <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $matterName }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        @endif
                                        <tr>
                                            <td style="padding-top:8px;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="font-size:12px; color:#64748B; font-weight:500;">Subject</td>
                                                        <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $subject }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </div>

                                <!-- Message preview bubble -->
                                <div style="border-left:3px solid #D97706; background:#FFFBEB; border-radius:0 8px 8px 0; padding:12px 16px; margin:16px 0 24px;">
                                    <p style="font-size:13px; color:#475569; line-height:1.7; margin:0; font-style:italic;">
                                        &ldquo;{{ $messagePreview }}&rdquo;
                                    </p>
                                </div>

                                <!-- Primary CTA -->
                                <div style="text-align:center; margin:28px 0 12px;">
                                    <a href="{{ $ctaUrl }}" style="display:inline-block; background-color:#D97706; color:#ffffff; font-weight:700; font-size:15px; letter-spacing:-0.01em; text-decoration:none; padding:13px 32px; border-radius:8px; text-align:center;">
                                        Open Messages
                                    </a>
                                </div>

                                <p style="font-size:11px; color:#94A3B8; text-align:center; margin-top:8px; word-break:break-all;">
                                    If the button above doesn&rsquo;t work, copy and paste this link into your browser:<br>
                                    <a href="{{ $ctaUrl }}" style="color:#B45309;">{{ $ctaUrl }}</a>
                                </p>

                                <hr style="border:none; border-top:1px solid #E2E8F0; margin:28px 0 20px;">

                                <p style="font-size:11px; color:#94A3B8; line-height:1.6; margin:0;">
                                    You&rsquo;re receiving this because a client message on a conversation assigned to you has gone unread.
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
