<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Domain\Support;

enum NotificationType: string
{
	case NewDocumentUploaded = 'new_doc_uploaded';
	case DocumentReadyForSignature = 'document_ready_for_signature';
	case SignatureCompleted = 'signature_completed';
	case MilestoneUpdated = 'milestone_updated';
	case NewMessageFromAClient = 'new_message_from_client';
	case UnreadMessageReminder = 'unread_message_reminder';
	case PaymentReceived = 'payment_received';
	case InvoiceOverdue = 'invoice_overdue';
	case SecurityAlerts = 'security_alerts';

	public function label(): string
	{
		return match($this) {
			self::NewDocumentUploaded => 'New document uploaded',
			self::DocumentReadyForSignature => 'Document ready for signature',
			self::SignatureCompleted => 'Signature completed',
			self::MilestoneUpdated => 'Milestone updated',
			self::NewMessageFromAClient => 'New message from a client',
			self::UnreadMessageReminder => 'Unread message reminder',
			self::PaymentReceived => 'Payment received',
			self::InvoiceOverdue => 'Invoice overdue',
			self::SecurityAlerts => 'Security alerts'
		};
	}

	public function defaultEmailSetting(): bool
	{
		return match($this) {
			self::NewDocumentUploaded, self::DocumentReadyForSignature,
			self::SignatureCompleted, self::NewMessageFromAClient,
			self::UnreadMessageReminder, self::PaymentReceived,
			self::InvoiceOverdue, self::SecurityAlerts => true,
			self::MilestoneUpdated => false
		};
	}

	public function defaultInAppSetting(): bool
	{
		return match($this) {
			self::NewDocumentUploaded, self::DocumentReadyForSignature,
			self::SignatureCompleted, self::NewMessageFromAClient,
			self::UnreadMessageReminder, self::PaymentReceived,
			self::InvoiceOverdue, self::SecurityAlerts => true,
			self::MilestoneUpdated => false
		};
	}

	public function defaultSmsSetting(): bool
	{
		return match($this) {
			self::NewDocumentUploaded, self::DocumentReadyForSignature,
			self::SignatureCompleted, self::NewMessageFromAClient,
			self::UnreadMessageReminder, self::PaymentReceived,
			self::InvoiceOverdue, self::MilestoneUpdated, self::SecurityAlerts => false,
		};
	}
}
