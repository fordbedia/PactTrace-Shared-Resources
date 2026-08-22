<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You've been asked to sign a document</title>
</head>
<body style="margin:0; padding:40px 16px; background:#E8EAF0; font-family:'Inter',Arial,sans-serif;">

<div style="max-width:600px; margin:0 auto;">
    <div style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(15,23,42,0.08); border:1px solid #E2E8F0;">

        <!-- Header Band -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#10162B;">
            <tr>
                <td style="padding:24px 32px;">
                    <span style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:-0.03em; font-family:'Inter',Arial,sans-serif;">{{ $providerName ?? 'PactTrack' }}</span>
                </td>
            </tr>
        </table>

        <!-- Canvas -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
            <tr>
                <td style="padding:32px 24px;">
                    <table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; border:1px solid #E2E8F0; overflow:hidden;">
                        <tr>
                            <td style="background:{{ $primaryColor ?? '#2563EB' }}; height:4px; padding:0; line-height:0; font-size:0;">&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="padding:36px 36px 28px;">

                                <h1 style="font-size:22px; font-weight:700; color:#0F172A; letter-spacing:-0.02em; margin:0 0 12px; line-height:1.3;">
                                    You've been asked to sign a document
                                </h1>

                                <p style="font-size:14px; color:#334155; line-height:1.7; margin:0 0 24px; max-width:460px;">
                                    Hi {{ $signerName }}, <strong style="color:#0F172A;">{{ $providerName ?? 'the sender' }}</strong> has sent <strong style="color:#0F172A;">{{ $documentName }}</strong> for your signature, as part of an agreement with {{ $clientName }}. No account or sign-up is needed &mdash; just review and sign below.
                                </p>

                                <div style="text-align:center; margin:28px 0 16px;">
                                    <a href="{{ $signingUrl }}" style="display:inline-block; background-color:{{ $primaryColor ?? '#2563EB' }}; color:#ffffff; font-weight:700; font-size:15px; letter-spacing:-0.01em; text-decoration:none; padding:13px 32px; border-radius:8px; text-align:center;">
                                        Review &amp; Sign
                                    </a>
                                </div>

                                <p style="font-size:11px; color:#94A3B8; text-align:center; margin-top:8px; word-break:break-all;">
                                    If the button above doesn't work, copy and paste this link into your browser:<br>
                                    <a href="{{ $signingUrl }}" style="color:{{ $primaryColor ?? '#2563EB' }};">{{ $signingUrl }}</a>
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
