<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * Everything a {@see Plan} allows, as one read-only data object.
 *
 * `Plan` is the identity/comparison type — the string in `providers.plan` /
 * `subscriptions.plan`, and what tier-ordering compares. `PlanInfo` is the
 * property bag hanging off it: a PHP backed enum case can only carry one
 * scalar, so "a collection of everything this plan allows" has to be a plain
 * data class instead of a second enum.
 *
 * `PlanInfo::for($plan)` is the only constructor; `Plan::info()` is the call
 * site everywhere else. Nothing outside this class should write a second
 * `match (Plan …)` or a raw `$provider->plan === 'starter'` string check — see
 * .claude/rules/plan.md for the full matrix and where each limit is (and
 * isn't) enforced today.
 *
 * Framework-free by the hexagonal rule in CLAUDE.md — `toArray()` is a plain
 * array, not an Illuminate contract, so `ProviderResource` spreads it in
 * without this class importing `Illuminate\*`.
 */
final class PlanInfo
{
    private function __construct(
        public readonly Plan $plan,
        public readonly string $label,
        /** null = unlimited. */
        public readonly ?int $maxSeats,
        /** null = unlimited. */
        public readonly ?int $maxActiveClients,
        public readonly int $storageLimitBytes,
        /** Human wording for {@see $storageLimitBytes}, e.g. "5 GB". */
        public readonly string $storageLimitLabel,
        /** null = unlimited. A flow limit — resets every billing cycle. */
        public readonly ?int $maxEnvelopesPerMonth,
        /** null = unlimited retention (never pruned). */
        public readonly ?int $auditLogRetentionDays,
        public readonly bool $allowsAuditLogExport,
        public readonly bool $allowsCustomDomain,
        public readonly bool $allowsCustomBranding,
        /** 'standard' | 'priority_sla' — display-only, nothing branches on it. */
        public readonly string $supportTier,
    ) {
    }

    public static function for(Plan $plan): self
    {
        return match ($plan) {
            Plan::Starter => new self(
                plan: Plan::Starter,
                label: 'Starter',
                maxSeats: 1,
                maxActiveClients: 10,
                storageLimitBytes: 5 * 1024 * 1024 * 1024,
                storageLimitLabel: '5 GB',
                maxEnvelopesPerMonth: 10,
                auditLogRetentionDays: 90,
                allowsAuditLogExport: false,
                allowsCustomDomain: false,
                allowsCustomBranding: false,
                supportTier: 'standard',
            ),
            Plan::Professional => new self(
                plan: Plan::Professional,
                label: 'Professional',
                maxSeats: 1,
                maxActiveClients: null,
                storageLimitBytes: 50 * 1024 * 1024 * 1024,
                storageLimitLabel: '50 GB',
                maxEnvelopesPerMonth: null,
                auditLogRetentionDays: null,
                allowsAuditLogExport: false,
                allowsCustomDomain: true,
                allowsCustomBranding: true,
                supportTier: 'standard',
            ),
            Plan::Firm => new self(
                plan: Plan::Firm,
                label: 'Firm',
                maxSeats: 5,
                maxActiveClients: null,
                storageLimitBytes: 200 * 1024 * 1024 * 1024,
                storageLimitLabel: '200 GB',
                maxEnvelopesPerMonth: null,
                auditLogRetentionDays: null,
                allowsAuditLogExport: true,
                allowsCustomDomain: true,
                allowsCustomBranding: true,
                supportTier: 'priority_sla',
            ),
        };
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan->value,
            'label' => $this->label,
            'max_seats' => $this->maxSeats,
            'max_active_clients' => $this->maxActiveClients,
            'storage_limit_bytes' => $this->storageLimitBytes,
            'storage_limit_label' => $this->storageLimitLabel,
            'max_envelopes_per_month' => $this->maxEnvelopesPerMonth,
            'audit_log_retention_days' => $this->auditLogRetentionDays,
            'allows_audit_log_export' => $this->allowsAuditLogExport,
            'allows_custom_domain' => $this->allowsCustomDomain,
            'allows_custom_branding' => $this->allowsCustomBranding,
            'support_tier' => $this->supportTier,
        ];
    }
}
