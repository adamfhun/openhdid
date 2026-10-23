<?php

namespace App\Auth\Oidc;

/**
 * The employee id a company sign-in carries (USER_/CLIENT_SSO_MATCH=employee_id):
 * the configured claim, in the ID token before the access token, compared
 * with the EMD record's external id. Like EmailClaimMatch, the audit gets
 * the claim name and the verdict, never the value of other claims.
 */
final readonly class EmployeeIdClaimMatch
{
    public function __construct(
        public ?int $externalId,
        public string $claim,
        public ?string $key,
        public ?string $token,
        public ?string $problem,
        public OidcTokens $tokens,
    ) {}

    public static function resolve(OidcTokens $tokens, OidcProviderConfig $config): self
    {
        $name = $config->employeeIdClaim;
        $problem = 'missing';

        foreach ($tokens->sources() as $token => $claims) {
            $key = OidcTokens::claimKey($claims, $name);
            if ($key === null) {
                continue;
            }

            [$id, $finding] = self::idIn($claims[$key]);
            if ($id !== null) {
                return new self($id, $name, $key, $token, null, $tokens);
            }

            $problem = $problem === 'missing' ? $finding : $problem;
        }

        return new self(null, $name, null, null, $problem, $tokens);
    }

    /**
     * What the audit entry of a matched sign-in records.
     *
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        return ['employee_id_claim' => $this->key, 'employee_id_token' => $this->token];
    }

    /**
     * What an operator needs when the claim gave no usable id.
     *
     * @return array<string, mixed>
     */
    public function diagnosis(): array
    {
        return array_filter([
            'employee_id_claim' => $this->claim,
            'employee_id_problem' => $this->problem,
            'id_token_claims' => array_keys($this->tokens->idClaims),
            'access_token' => $this->tokens->accessStatus === OidcTokens::ACCESS_NOT_READ ? null : $this->tokens->accessStatus,
            'access_token_claims' => $this->tokens->accessClaims === null ? null : array_keys($this->tokens->accessClaims),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array{0: ?int, 1: string} the id, or null with the reason
     */
    private static function idIn(mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        $strings = array_values(array_unique(array_map(fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $values)));
        $ids = array_values(array_filter($strings, fn (string $item): bool => preg_match('/^\d{1,18}$/', $item) === 1));

        return match (true) {
            count($ids) === 1 => [(int) $ids[0], 'ok'],
            count($ids) > 1 => [null, 'several_values'],
            implode('', $strings) === '' => [null, 'empty'],
            default => [null, 'not_numeric'],
        };
    }
}
