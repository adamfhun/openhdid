<?php

namespace App\System;

use Illuminate\Support\Carbon;

/**
 * Outcome of one health check.
 *
 * @phpstan-type CheckArray array{key: string, label: string, status: string, detail: string, checked_at: string}
 *
 * `hint` tells the operator what to do when the check is not green; it is
 * static per check key so the status page can show it on every tile.
 */
final readonly class Check
{
    public function __construct(
        public string $key,
        public string $label,
        public CheckStatus $status,
        public string $detail,
        public Carbon $checkedAt,
        public ?string $hint = null,
    ) {}

    public function withHint(?string $hint): self
    {
        return new self($this->key, $this->label, $this->status, $this->detail, $this->checkedAt, $hint);
    }

    public static function ok(string $key, string $label, string $detail): self
    {
        return new self($key, $label, CheckStatus::Ok, $detail, now());
    }

    public static function warn(string $key, string $label, string $detail): self
    {
        return new self($key, $label, CheckStatus::Warn, $detail, now());
    }

    public static function fail(string $key, string $label, string $detail): self
    {
        return new self($key, $label, CheckStatus::Fail, $detail, now());
    }
}
