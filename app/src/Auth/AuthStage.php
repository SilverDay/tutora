<?php

declare(strict_types=1);

namespace Tutora\Auth;

enum AuthStage: string
{
    /** password verified, TOTP code still required */
    case MfaPending = 'mfa_pending';
    /** password verified, account has no TOTP yet: must enrol before anything else */
    case MfaEnrollment = 'mfa_enroll';
    /** fully authenticated (password + TOTP) */
    case Full = 'full';
}
