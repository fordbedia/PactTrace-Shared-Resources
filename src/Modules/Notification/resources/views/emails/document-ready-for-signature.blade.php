<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A document is ready for your signature</title>
</head>
<body style="margin:0; padding:40px 16px; background:#E8EAF0; font-family:'Inter',Arial,sans-serif;">

@php
    // White-labeling gate — see the header/footer partials' docblocks. The
    // accent (CTA + top stripe) is the tenant's own colour only when branded;
    // otherwise the template's existing default, unchanged.
    $brandingEnabled = $brandingEnabled ?? false;
    $accent = ($brandingEnabled && !empty($primaryColor)) ? $primaryColor : '#2563EB';
    $portalPhrase = $brandingEnabled ? 'secure portal' : 'secure PactTrack portal';
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
                            <td style="background:{{ $accent }}; height:4px; padding:0; line-height:0; font-size:0;">&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="padding:36px 36px 28px;">

                                <h1 style="font-size:22px; font-weight:700; color:#0F172A; letter-spacing:-0.02em; margin:0 0 12px; line-height:1.3;">
                                    A document is ready for your signature
                                </h1>

                                <p style="font-size:14px; color:#334155; line-height:1.7; margin:0 0 24px; max-width:460px;">
                                    Hi {{ $clientName }}, <strong style="color:#0F172A;">{{ $providerName ?? 'your provider' }}</strong> has sent <strong style="color:#0F172A;">{{ $documentName }}</strong> to your {{ $portalPhrase }} for signature.@if(!empty($workspaceName)) This relates to your work with <strong style="color:#0F172A;">{{ $workspaceName }}</strong>.@endif
                                </p>

                                <div style="text-align:center; margin:28px 0 16px;">
                                    <a href="{{ $portalUrl }}" style="display:inline-block; background-color:{{ $accent }}; color:#ffffff; font-weight:700; font-size:15px; letter-spacing:-0.01em; text-decoration:none; padding:13px 32px; border-radius:8px; text-align:center;">
                                        Review &amp; Sign
                                    </a>
                                </div>

                                <p style="font-size:11px; color:#94A3B8; text-align:center; margin-top:8px; word-break:break-all;">
                                    If the button above doesn't work, copy and paste this link into your browser:<br>
                                    <a href="{{ $portalUrl }}" style="color:{{ $accent }};">{{ $portalUrl }}</a>
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
