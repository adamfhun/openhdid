<?php

namespace App\Auth\Oidc;

/**
 * One Entra tenant whose users may sign in, with the e-mail domains it may
 * present. No domain means a trusted tenant: any address the EMD knows.
 */
final readonly class EntraTenant
{
    /**
     * @param  list<string>  $domains  lower-case, without the "@"; empty = any domain
     */
    public function __construct(
        public string $id,
        public array $domains = [],
    ) {}

    public function isTrustedForAnyDomain(): bool
    {
        return $this->domains === [];
    }

    /**
     * Whether an account with this address may be signed in from this tenant.
     */
    public function allowsAddress(string $email): bool
    {
        if ($this->domains === []) {
            return true;
        }

        $at = strrpos($email, '@');
        $domain = $at === false ? '' : mb_strtolower(substr($email, $at + 1));

        return $domain !== '' && in_array($domain, $this->domains, true);
    }

    /**
     * The tenant as it is written in the environment: the id, with the
     * domains after a colon, separated by "|".
     */
    public function describe(): string
    {
        return $this->domains === [] ? $this->id : $this->id.':'.implode('|', $this->domains);
    }
}
