<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $invitedByName }} invited you to join {{ $providerName }} on PactTrack</title>
</head>
<body style="margin:0; padding:40px 16px; background:#E4E7EE; font-family:'Inter',Arial,sans-serif;">

@php
    // Design: Dashboard/Client-facing-provider-email-template.html — the
    // provider-branded card (their accent + logo), PactTrack-powered footer.
    $accent  = $primaryColor ?: '#1D4ED8';
    $initial = strtoupper(substr($providerName ?: 'P', 0, 1));
@endphp

<div style="max-width:600px; margin:0 auto;">
    <div style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(15,23,42,0.07); border:1px solid #E2E8F0;">

        <!-- Header: the inviting firm's own branding -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC; border-bottom:1px solid #E2E8F0;">
            <tr>
                <td style="padding:20px 28px;">
                    <table cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                            <td style="vertical-align:middle;">
                                <table cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="vertical-align:middle; padding-right:9px;">
                                            @if(!empty($logoUrl))
                                                <img src="{{ $logoUrl }}" alt="{{ $providerName }}" width="32" height="32" style="display:block; border-radius:7px; width:32px; height:32px; object-fit:cover;">
                                            @else
                                                <div style="width:32px; height:32px; background:{{ $accent }}; border-radius:7px; display:flex; align-items:center; justify-content:center; text-align:center; line-height:32px;">
                                                    <span style="font-size:13px; font-weight:800; color:#fff; letter-spacing:-0.03em;">{{ $initial }}</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td style="vertical-align:middle;">
                                            <span style="font-size:16px; font-weight:700; color:#0F172A; letter-spacing:-0.02em;">{{ $providerName }}</span>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                            <td style="text-align:right; vertical-align:middle;">
                                <span style="font-size:10px; font-weight:500; color:#94A3B8; letter-spacing:0.03em;">Team workspace</span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Canvas -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
            <tr>
                <td style="padding:28px 20px;">
                    <table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; border:1px solid #E2E8F0;">
                        <!-- Accent top bar using the firm's own color -->
                        <tr>
                            <td style="background:{{ $accent }}; height:4px; padding:0; border-radius:10px 10px 0 0; line-height:0; font-size:0;">&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="padding:32px 32px 28px;">

                                <!-- Icon -->
                                <div style="width:44px; height:44px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; display:flex; align-items:center; justify-content:center; margin-bottom:18px; text-align:center; line-height:44px;">
                                    <span style="font-size:18px; color:{{ $accent }};">&#9993;</span>
                                </div>

                                <p style="font-size:11px; font-weight:700; color:#94A3B8; text-transform:uppercase; letter-spacing:0.07em; margin:0 0 8px;">Team Invitation</p>
                                <h1 style="font-size:20px; font-weight:700; color:#0F172A; letter-spacing:-0.02em; margin:0 0 10px; line-height:1.3;">
                                    {{ $invitedByName }} invited you to join {{ $providerName }}
                                </h1>
                                <p style="font-size:14px; color:#334155; line-height:1.7; margin:0 0 20px; max-width:440px;">
                                    <strong style="color:#0F172A;">{{ $invitedByName }}</strong> has invited you to join the <strong style="color:#0F172A;">{{ $providerName }}</strong> team on PactTrack &mdash; the workspace where the firm manages client documents, e&#8209;signatures, and messaging. Accept below to set your password and sign in.
                                </p>

                                <!-- Detail box -->
                                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:16px 20px; margin:0 0 24px;">
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="padding-bottom:10px; border-bottom:1px solid #E2E8F0;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="font-size:12px; color:#64748B; font-weight:500;">Invited by</td>
                                                        <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $invitedByName }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding-top:10px; {{ !empty($title) ? 'padding-bottom:10px; border-bottom:1px solid #E2E8F0;' : '' }}">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="font-size:12px; color:#64748B; font-weight:500;">Role</td>
                                                        <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $roleLabel }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        @if(!empty($title))
                                        <tr>
                                            <td style="padding-top:10px;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="font-size:12px; color:#64748B; font-weight:500;">Title</td>
                                                        <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $title }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        @endif
                                    </table>
                                </div>

                                <!-- CTA -->
                                <div style="text-align:center; margin:24px 0 10px;">
                                    <a href="{{ $acceptUrl }}" style="display:inline-block; background-color:{{ $accent }}; color:#ffffff !important; font-weight:700; font-size:15px; letter-spacing:-0.01em; text-decoration:none; padding:13px 36px; border-radius:8px; text-align:center;">
                                        Accept Invitation &rarr;
                                    </a>
                                </div>
                                <p style="font-size:11px; color:#94A3B8; text-align:center; margin:6px 0 0; word-break:break-all;">
                                    Or copy this link: <a href="{{ $acceptUrl }}" style="color:{{ $accent }}; text-decoration:underline;">{{ $acceptUrl }}</a>
                                </p>

                                @if(!empty($expiresAt))
                                <p style="font-size:11px; color:#94A3B8; text-align:center; margin:14px 0 0;">
                                    This invitation expires on {{ $expiresAt }}.
                                </p>
                                @endif

                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Footer -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F2F5;">
            <tr>
                <td style="padding:0 20px 24px;">
                    <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="text-align:center; padding:18px 12px; border-top:1px solid #E2E8F0;">
                                <p style="font-size:11px; color:#94A3B8; margin:0 0 6px; line-height:1.7;">{{ $providerName }} uses PactTrack to manage client documents and communication.</p>
                                <p style="font-size:11px; color:#94A3B8; margin:0 0 8px; line-height:1.7;">This invitation was sent through a secure, logged channel. If you weren&rsquo;t expecting it, you can ignore this email &mdash; it was sent to <strong style="color:#64748B;">{{ $email }}</strong>.</p>
                                <p style="font-size:11px; color:#94A3B8; margin:0;">Powered by <a href="#" style="color:#64748B; text-decoration:underline; font-weight:600;">PactTrack</a></p>
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
