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
    no_email_claim: 'The sign-in service did not send a usable e-mail address, so the account cannot be identified. Please report this to the operator of the system.',
    no_employee_id_claim: 'The sign-in service did not send a usable employee id, so the account cannot be identified. Please report this to the operator of the system.',
    no_subject_claim: 'The sign-in service did not send a stable user identifier, so the sign-in cannot be bound to the account. Please report this to the operator of the system.',
    sso_subject_mismatch: 'This sign-in comes from a different directory identity than the one bound to the account. Ask an administrator to check the account and clear its sign-in binding.',
    sso_subject_taken: 'This directory identity is already bound to another account. Ask an administrator to check the accounts.',
    session_expired: 'Your session has expired. Please sign in again.',
};
