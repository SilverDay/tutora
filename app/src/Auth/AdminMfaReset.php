<?php

declare(strict_types=1);

namespace Tutora\Auth;

use Tutora\Audit\AuditLog;

/**
 * Audited administrative MFA reset (owner decision 3a) for a tutor who lost both the
 * authenticator and the recovery codes. Clears TOTP and all recovery codes; the tutor must
 * enrol again at the next sign-in and is notified by email.
 */
final class AdminMfaReset
{
    public function __construct(
        private readonly TutorAccounts $accounts,
        private readonly RecoveryCodes $recovery,
        private readonly AuditLog $audit,
        private readonly AccountNotices $notices,
        private readonly TutorRealtimeRevoker $realtime,
    ) {
    }

    /** @return bool false if no such account */
    public function reset(string $email, string $operator, string $reason): bool
    {
        $operator = trim($operator);
        $reason = trim($reason);
        if ($operator === '' || mb_strlen($reason, 'UTF-8') < 5) {
            throw new \InvalidArgumentException('An operator name and a reason (min. 5 characters) are required');
        }
        $account = $this->accounts->findByEmail(TutorAuthService::normalizeEmail($email));
        if ($account === null) {
            return false;
        }
        $id = (int) $account['id'];
        $this->accounts->resetTotp($id); // also ends all HTTP sessions (auth epoch)
        $this->realtime->revokeAll($id);
        $this->recovery->deleteAll($id);
        $this->audit->record($id, AuditLog::MFA_RESET, null, ['operator' => mb_substr($operator, 0, 100), 'reason' => mb_substr($reason, 0, 500)]);
        $this->notices->mfaReset((string) $account['email']);
        return true;
    }
}
