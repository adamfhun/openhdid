<?php

namespace App\Auth\Oidc;

/**
 * Which claim binds a sign-in to an account, and why the claims before it
 * were passed over. The audit gets claim names and verdicts, never values.
 */
final readonly class EmailClaimMatch
{
    public const ID_TOKEN = 'id_token';

    public const ACCESS_TOKEN = 'access_token';

    /**
     * @param  list<string>  $checked  claim names in the order they were tried
     * @param  array<string, string>  $passedOver  claim name => why it did not decide
     */
    public function __construct(
        public ?string $email,
        public ?string $claim,
        public ?string $token,
        public array $checked,
        public array $passedOver,
        public OidcTokens $tokens,
    ) {}

    /**
     * ADFS: the configured claims in order, the ID token before the access
     * token for each. Entra: its `email` claim mirrors an editable directory
     * attribute, so it counts only when the tenant marks it verified
     * (`xms_edov`); otherwise the UPN-based claims decide, whose domain the
     * issuing tenant had to prove.
     */
    public static function resolve(OidcTokens $tokens, OidcProviderConfig $config): self
    {
        $order = $config->emailClaims;
        $passedOver = [];

        if ($config->provider === OidcProvider::Entra) {
            $claims = $tokens->idClaims;
            $verified = in_array($claims['xms_edov'] ?? null, [true, 1, '1', 'true'], true)
                || in_array($claims['email_verified'] ?? null, [true, 1, '1', 'true'], true);
            $order = $verified ? ['email', 'preferred_username', 'upn', 'unique_name'] : ['preferred_username', 'upn', 'unique_name'];

            if (! $verified && array_key_exists('email', $claims)) {
                $passedOver['email'] = 'unverified';
            }
        }

        $sources = array_filter([self::ID_TOKEN => $tokens->idClaims, self::ACCESS_TOKEN => $tokens->accessClaims], fn (?array $claims): bool => $claims !== null);

        foreach ($order as $name) {
            $verdict = 'missing';

            foreach ($sources as $token => $claims) {
                $key = self::keyOf($claims, $name);
                if ($key === null) {
                    continue;
                }

                [$email, $finding] = self::addressIn($claims[$key]);
                if ($email !== null) {
                    return new self($email, $key, $token, $order, $passedOver, $tokens);
                }

                $verdict = $verdict === 'missing' ? $finding : $verdict;
            }

            $passedOver[$name] = $verdict;
        }

        return new self(null, null, null, $order, $passedOver, $tokens);
    }

    /**
     * What the audit entry of a matched sign-in records: the deciding claim,
     * the claims passed over before it, and whether the access token counted.
     *
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        return array_filter([
            'email_claim' => $this->claim,
            'email_token' => $this->token,
            'claims_passed_over' => $this->passedOver,
            'access_token' => $this->accessStatus(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Everything an operator needs when no claim matched: what was looked
     * for, why each failed, and which claim names the tokens did carry.
     *
     * @return array<string, mixed>
     */
    public function diagnosis(): array
    {
        return array_filter([
            'claims_checked' => $this->checked,
            'claims_passed_over' => $this->passedOver,
            'id_token_claims' => array_keys($this->tokens->idClaims),
            'access_token' => $this->accessStatus(),
            'access_token_claims' => $this->tokens->accessClaims === null ? null : array_keys($this->tokens->accessClaims),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function accessStatus(): ?string
    {
        return $this->tokens->accessStatus === OidcTokens::ACCESS_NOT_READ ? null : $this->tokens->accessStatus;
    }

    /**
     * The claim key for a configured name: the name itself, or a claim-type
     * URI ending in it (".../claims/emailaddress" for "emailaddress").
     *
     * @param  array<string, mixed>  $claims
     */
    private static function keyOf(array $claims, string $name): ?string
    {
        if (array_key_exists($name, $claims)) {
            return $name;
        }

        if (str_contains($name, '/')) {
            return null;
        }

        foreach (array_keys($claims) as $key) {
            if (str_contains((string) $key, '/') && strcasecmp(substr((string) $key, strrpos((string) $key, '/') + 1), $name) === 0) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: string} the address, or null with the reason
     */
    private static function addressIn(mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        $strings = array_map(fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $values);
        $addresses = array_values(array_unique(array_map(
            fn (string $item): string => mb_strtolower($item),
            array_filter($strings, fn (string $item): bool => filter_var($item, FILTER_VALIDATE_EMAIL) !== false),
        )));

        return match (true) {
            count($addresses) === 1 => [$addresses[0], 'ok'],
            count($addresses) > 1 => [null, 'several_addresses'],
            implode('', $strings) === '' => [null, 'empty'],
            collect($strings)->contains(fn (string $item): bool => str_contains($item, '\\')) => [null, 'domain_account'],
            default => [null, 'not_an_email'],
        };
    }
}
