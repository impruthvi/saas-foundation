<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

use RuntimeException;

/** Groups distinct invitation refusals behind one catchable domain exception. */
abstract class InvitationRefused extends RuntimeException {}
