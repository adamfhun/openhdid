/**
 * Login refusal reasons (LoginRejection on the server) and their messages;
 * the login page and the login link's landing page share them.
 */
export const loginErrorMessages = {
    method_disabled: 'This login method is not available.',
    no_account: 'No account exists for this identity.',
    account_closed: 'This account has been closed.',
    account_locked: 'Too many failed attempts: this login is locked for now. Try again later or ask for it to be unlocked.',
    no_external_record: 'This account is not present in Enterprise Master Data.',
    external_record_missing: 'This account is not present in Enterprise Master Data.',
    external_record_mismatch: 'This account does not match its Enterprise Master Data record.',
    no_permission: 'This account has no access.',
    not_entitled: 'Your package does not include access to the client portal.',
    invalid_credentials: 'The login link or code is not valid or has expired.',
    provider_unavailable: 'The sign-in service is not available right now. Please try again later.',
    session_expired: 'Your session has expired. Please sign in again.',
};
