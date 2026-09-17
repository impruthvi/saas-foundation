<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

use RuntimeException;

/**
 * The base of every reason an invitation cannot be issued or taken.
 *
 * M2's definition of done is that expiry, revocation, the wrong recipient and a
 * re-invite are each refused with a *distinct* error. A single exception
 * carrying a reason string would satisfy the letter of that and none of its
 * point, so each reason is its own class and the tests assert the class.
 *
 * The base exists so a caller catches once:
 *
 *   try { $accept->handle($token, $user); }
 *   catch (InvitationRefused $refused) { // one arm, eight reasons
 *
 * A ninth reason therefore costs a class and a message, never a new catch arm.
 *
 * Deliberately not in this hierarchy: a token that resolves to nothing. A
 * rotated, mistyped or never-issued token is indistinguishable from the outside
 * and is a 404, not a refusal — telling someone their link "expired" when it was
 * replaced by a resend is a statement that was never true.
 */
abstract class InvitationRefused extends RuntimeException {}
