# Member account activation

Milestone 8 makes the linked-member portal reachable without letting the browser choose tenant ownership or member identity.

## Staff workflow

1. An owner, manager or receptionist opens the selected gym's **Members** view.
2. The member must have a valid email and no linked portal account.
3. **Invite portal** creates a 48-hour invitation. The API returns the opaque activation value once.
4. Copy the displayed fragment link and deliver it through the gym's approved private channel.
5. Reissuing an invitation revokes the previous pending value under the member row lock.

The activation value must not be pasted into tickets, analytics, logs or audit notes. Production notification delivery should place it only in the intended member's message.

## Member workflow

1. The web app reads `activate_gym` and `activate_token` from the URL fragment into volatile component state.
2. It immediately replaces the visible URL, before preview or acceptance is requested.
3. Preview returns only the gym name, member first name, masked email and whether the email already has an IronCore account.
4. New users create a password of at least 12 characters. Existing users keep their current password.
5. Acceptance locks and revalidates the invitation and member, creates or links the user, adds the tenant `member` role, links `members.user_id`, consumes the invitation and records audit evidence in one transaction.
6. The web session identifier is regenerated before the member portal opens.

## Security boundaries

- `member_account_invitations` is tenant-owned, uses composite member integrity, tenant-leading indexes and forced PostgreSQL RLS.
- The database stores `SHA-256(lowercase gym UUID + "|" + opaque token)`; plaintext is returned once and hidden from resources.
- Public preview and acceptance are limited by route gym plus IP and bind `TenantContext` before token lookup.
- A changed member email, linked member, expired/revoked/accepted invitation or wrong gym receives the same invalid-token response.
- An account already linked to another member in the gym is rejected.
- An existing staff role in the gym and any platform administrator account are rejected instead of converted or downgraded.
- Opening an invitation while signed in as a different email is rejected before account mutation.

## API surface

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/gyms/{gym}/members/{member}/account-invitations` | Bounded tenant invitation history |
| `POST` | `/api/v1/gyms/{gym}/members/{member}/account-invitations` | Revoke/reissue and return one activation value |
| `POST` | `/api/v1/gyms/{gym}/member-account-invitations/preview` | Safe public preview with token in JSON body |
| `POST` | `/api/v1/gyms/{gym}/member-account-invitations/accept` | Atomic link/create and stateful session start |

## Production gate

Before deployment, run the Laravel feature suite against PostgreSQL with forced RLS enabled, Redis available and the same stateful-domain/session configuration used by the web origin. Confirm concurrent reissue and acceptance behavior, session-cookie security attributes, mail-provider redaction, expiry cleanup and audit retention in the target environment.
