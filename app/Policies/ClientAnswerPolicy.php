<?php

namespace App\Policies;

/**
 * Removing an answer takes away one of the client's means of identification,
 * so it belongs to client management; reading which questions are answered
 * goes with viewing the client. Out of reach when the client is not served.
 */
class ClientAnswerPolicy extends ClientPolicy {}
