<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Domain\Exceptions;

use RuntimeException;

/**
 * The name/password confirmation on the "Deactivate Workspace" modal did not
 * match the acting user's account. `reason` is `'name'` or `'password'`; the
 * controller maps it onto that field as a 422 validation error.
 *
 * The confirmation is checked against the acting user (their own name and
 * password), not against the workspace — same as the User module's account
 * deletion flow.
 */
final class WorkspaceDeactivationConfirmationException extends RuntimeException
{
    /**
     * @param  'name'|'password'  $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Workspace deactivation confirmation failed ({$reason}).");
    }
}
