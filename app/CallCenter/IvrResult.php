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
