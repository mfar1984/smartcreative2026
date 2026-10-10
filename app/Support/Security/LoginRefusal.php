<?php

namespace App\Support\Security;

/**
 * Why a sign in whose PASSWORD already checked out was still refused.
 *
 * mayEnter() returns one of these instead of a bare false, so authenticate() can
 * word the message to fit the reason without re-deriving it or loosening who is
 * actually let in. A null return from mayEnter() means "let them in"; any case here
 * means "the password was right but the account still may not enter", and each one
 * reads differently to the operator:
 *
 *   BANNED        the address is blocked and this is not a super admin who can lift
 *                 it. Handled on its own path, with its existing Security Log entry.
 *   NOT_ALLOWLISTED  the address is not on the admin IP allowlist. Its own path too,
 *                 with its existing Security Log entry.
 *   CANNOT_ACCESS the account's role carries no admin.access, the account is
 *                 inactive, or its role is inactive. A misconfiguration, not an
 *                 attack: the owner ticked the wrong box, not an intruder. Worded
 *                 plainly for the operator and logged to the ordinary activity log,
 *                 NOT the Security Log.
 */
enum LoginRefusal
{
    case BANNED;
    case NOT_ALLOWLISTED;
    case CANNOT_ACCESS;
}
