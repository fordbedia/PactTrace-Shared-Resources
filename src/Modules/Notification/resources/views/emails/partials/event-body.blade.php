{{--
    The shared inner body of a Variant D system-notification email: icon chip,
    heading, one intro line, an optional italic quote block, an optional
    label/value detail box, and the amber CTA. Every internal-recipient email
    in .claude/rules/notification.md's dispatch-site table renders through this
    so they can't visually drift.

    Params: icon (emoji), heading, intro, ctaLabel, ctaUrl,
            rows (list<['label'=>..,'value'=>..]>, optional), quote (optional).
--}}
<table cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
    <tr>
        <td style="width:48px; height:48px; background:#FEF3C7; border-radius:12px; text-align:center; vertical-align:middle; font-size:22px;">
            {{ $icon }}
        </td>
    </tr>
</table>

<h1 style="font-size:22px; font-weight:700; color:#0F172A; letter-spacing:-0.02em; margin:0 0 12px; line-height:1.3;">
    {{ $heading }}
</h1>

<p style="font-size:14px; color:#334155; line-height:1.7; margin:0 0 20px; max-width:460px;">
    {{ $intro }}
</p>

@if (! empty($quote))
    <div style="border-left:3px solid #D97706; background:#FFFBEB; border-radius:0 8px 8px 0; padding:12px 16px; margin:16px 0 24px;">
        <p style="font-size:13px; color:#475569; line-height:1.7; margin:0; font-style:italic;">&ldquo;{{ $quote }}&rdquo;</p>
    </div>
@endif

@if (! empty($rows))
    <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:6px 18px; margin:20px 0;">
        <table width="100%" cellpadding="0" cellspacing="0">
            @foreach ($rows as $row)
                @include('notification::emails.partials.detail-row', [
                    'label' => $row['label'],
                    'value' => $row['value'],
                    'last' => $loop->last,
                ])
            @endforeach
        </table>
    </div>
@endif

<div style="text-align:center; margin:28px 0 12px;">
    <a href="{{ $ctaUrl }}" style="display:inline-block; background-color:#D97706; color:#ffffff; font-weight:700; font-size:15px; letter-spacing:-0.01em; text-decoration:none; padding:13px 32px; border-radius:8px; text-align:center;">
        {{ $ctaLabel }}
    </a>
</div>

<p style="font-size:11px; color:#94A3B8; text-align:center; margin-top:8px; word-break:break-all;">
    If the button above doesn&rsquo;t work, copy and paste this link into your browser:<br>
    <a href="{{ $ctaUrl }}" style="color:#B45309;">{{ $ctaUrl }}</a>
</p>
