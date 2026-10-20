<?php

namespace App\Auth\Mfa;

use RuntimeException;

/**
 * A second-factor step was refused; the message is translated and shown
 * to the staff member as it is.
 */
class MfaException extends RuntimeException {}
