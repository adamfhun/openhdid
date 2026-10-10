<?php

namespace App\Policies;

/**
 * A phone number goes with its client: same permissions, and out of reach
 * when the client is not served.
 */
class ClientPhoneNumberPolicy extends ClientPolicy {}
