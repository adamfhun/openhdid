<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Auth\AccountLogin;
use App\Clients\ClientTiers;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PrincipalType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Identification\MobileOtpService;
use App\Models\Client;
use App\Support\EmailAddressRule;
use App\Sync\ExternalRecordDto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Server-to-server endpoint for the mobile app backend: issue an IVR
 * identification code for a client it has already authenticated.
 */
class IvrCodeController extends Controller
{
    /**
     * Issue an IVR identification code for a client.
     *
     * Identify the client by `email` (at most 254 characters, 64 before
     * the @) or by `external_id` (one to seven digits). The address is
     * matched exactly (letter case and surrounding spaces aside); a
     * look-alike address (accents, full-width characters) counts as unknown
     * (422). Any live IVR code
     * of the client is replaced. The code is meant to be sent by the app into
     * the phone menu (DTMF); it is accepted by the IVR only, not by
     * the agent's verification page.
     *
     * @response array{code: string, formatted: string, expires_at: string, client_id: string}
     */
    public function __invoke(Request $request, MobileOtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:external_id', 'nullable', 'string', new EmailAddressRule],
            'external_id' => ['required_without:email', 'nullable', 'integer', 'regex:'.ExternalRecordDto::EXTERNAL_ID_PATTERN],
        ]);

        $client = $this->clientFor($data);

        if ($client === null) {
            throw ValidationException::withMessages(['client' => __('No open client account matches the given identity.')]);
        }

        // A client whose package grants no portal access (in premium-only mode
        // every standard client) gets no code: the phone menu would refuse it.
        if (! app(ClientTiers::class)->isEntitled($client)) {
            throw ValidationException::withMessages(['client' => __('This client is not entitled to identification codes.')]);
        }

        $issued = $otp->issue($client, 'mobile_backend', OneTimeCodePurpose::IvrCode, [
            'api_key' => AuthenticateApiKey::identityOf($request),
        ]);

        return response()->json([
            'code' => $issued['code'],
            'formatted' => $issued['formatted'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'client_id' => $client->id,
        ]);
    }

    /**
     * The open client both given identities name. The address is matched the
     * way the login matches it (AccountLogin::findByEmail): the database
     * collation is accent-, case- and width-insensitive, so a look-alike
     * address ("víctim@…", an IDN domain) would otherwise find another
     * client and hand out that client's code; it counts as no client.
     *
     * @param  array{email?: string|null, external_id?: int|string|null}  $data
     */
    private function clientFor(array $data): ?Client
    {
        $query = Client::query()->open()
            ->when(isset($data['external_id']), fn ($query) => $query->whereHas('externalRecord', fn ($record) => $record->where('external_id', $data['external_id'])));

        if (! isset($data['email'])) {
            return $query->first();
        }

        $byEmail = app(AccountLogin::class)->findByEmail(PrincipalType::Client, $data['email']);

        return $byEmail instanceof Client ? $query->whereKey($byEmail->getKey())->first() : null;
    }
}
