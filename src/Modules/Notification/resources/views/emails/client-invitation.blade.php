<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're invited to your client portal</title>
</head>
<body style="margin:0; padding:40px 16px; background:#E8EAF0; font-family:'Inter',Arial,sans-serif;">

@php
    // White-labeling gate — see the header/footer partials' docblocks. This
    // template's historical default accent is amber (#D97706), not the blue
    // the signature emails use; keep it when unbranded.
    $brandingEnabled = $brandingEnabled ?? false;
    $accent = ($brandingEnabled && !empty($primaryColor)) ? $primaryColor : '#D97706';
    $portalPhrase = $brandingEnabled ? 'a secure client portal' : 'a secure PactTrack portal';
@endphp

<div style="max-width:600px; margin:0 auto;">
    <div style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(15,23,42,0.08); border:1px solid #E2E8F0;">

        @include('notification::emails.partials.client-email-header', [
            'providerName' => $providerName ?? 'PactTrack',
            'logoUrl' => $logoUrl ?? null,
            'brandingEnabled' => $brandingEnabled,
        ])

        <!-- Canvas -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
            <tr>
                <td style="padding:32px 24px;">
                    <table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; border:1px solid #E2E8F0; overflow:hidden;">
                        <tr>
                            <!-- Top accent bar -->
                            <td style="background:{{ $accent }}; height:4px; padding:0; line-height:0; font-size:0;">&nbsp;</td>
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
                                    <strong style="color:#0F172A;">{{ $invitedByName }}</strong> at <strong style="color:#0F172A;">{{ $providerName }}</strong> has invited you to {{ $portalPhrase }}, where you can view documents, track progress, and sign agreements online — all in one place.
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
                                    <a href="{{ $acceptUrl }}" style="display:inline-block; background-color:{{ $accent }}; color:#ffffff; font-weight:700; font-size:15px; letter-spacing:-0.01em; text-decoration:none; padding:13px 32px; border-radius:8px; text-align:center;">
                                        Accept Invitation
                                    </a>
                                </div>

                                <!-- Raw link fallback -->
                                <p style="font-size:11px; color:#94A3B8; text-align:center; margin-top:8px; word-break:break-all;">
                                    If the button above doesn't work, copy and paste this link into your browser:<br>
                                    <a href="{{ $acceptUrl }}" style="color:{{ $accent }};">{{ $acceptUrl }}</a>
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

        @include('notification::emails.partials.client-email-footer', [
            'providerName' => $providerName ?? 'PactTrack',
            'brandingEnabled' => $brandingEnabled,
        ])

    </div>
</div>

</body>
</html>
