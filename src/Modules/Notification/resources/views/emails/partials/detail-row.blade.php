{{-- One label/value line inside a Variant D "detail row" box. Pass `last => true` on the final row to drop its divider. --}}
<tr>
    <td style="padding:8px 0; @unless($last ?? false)border-bottom:1px solid #E2E8F0;@endunless">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="font-size:12px; color:#64748B; font-weight:500;">{{ $label }}</td>
                <td style="text-align:right; font-size:13px; font-weight:600; color:#0F172A;">{{ $value }}</td>
            </tr>
        </table>
    </td>
</tr>
