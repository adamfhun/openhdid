<?php

namespace App\CallCenter;

use App\Models\IdSession;

final readonly class IvrResult
{
    public function __construct(
        public bool $identified,
        public string $result,
        public ?string $sessionId = null,
        public ?string $clientId = null,
        public ?string $clientName = null,
    ) {}

    public static function unknownCaller(): self
    {
        return new self(false, 'unknown_caller');
    }

    public static function unknownCode(): self
    {
        return new self(false, 'unknown_code');
    }

    /**
     * The code identified the caller, but the call stays with the client it
     * is matched to (someone holds it, it is identified, or it has ended).
     */
    public static function codeOkCallKept(IdSession $session): self
    {
        return new self(true, 'code_ok_call_kept', $session->id, $session->client_id, $session->client?->name);
    }

    public static function fromSession(IdSession $session): self
    {
        return new self($session->status->isSuccessful(), (string) $session->outcome_reason, $session->id, $session->client_id, $session->client?->name);
    }

    /**
     * @return array{identified: bool, result: string, session_id: ?string, client_id: ?string, client_name: ?string}
     */
    public function toArray(): array
    {
        return ['identified' => $this->identified, 'result' => $this->result, 'session_id' => $this->sessionId, 'client_id' => $this->clientId, 'client_name' => $this->clientName];
    }
}
