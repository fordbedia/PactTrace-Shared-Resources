{{--
    The one Variant D system-notification body. Every internal-recipient
    Mailable in .claude/rules/notification.md's dispatch-site table
    (NewDocumentUploadedEmail, SignatureCompletedEmail, MilestoneUpdatedEmail,
    NewMessageFromClientEmail, SecurityAlertEmail) renders through this same
    view and only varies the `with(...)` data — icon, copy, detail rows, CTA.
    StaffUnreadMessageReminderEmail predates this and keeps its own standalone
    blade.
--}}
@extends('notification::emails.layouts.system', ['title' => $title])

@section('footnote', $footnote)

@section('body')
    @include('notification::emails.partials.event-body', [
        'icon' => $icon,
        'heading' => $heading,
        'intro' => $intro,
        'rows' => $rows ?? [],
        'quote' => $quote ?? null,
        'ctaLabel' => $ctaLabel,
        'ctaUrl' => $ctaUrl,
    ])
@endsection
