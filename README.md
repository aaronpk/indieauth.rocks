# IndieAuth Rocks

A test suite for the [IndieAuth](https://indieauth.spec.indieweb.org/) protocol, for **servers** and **clients**, in the spirit of [micropub.rocks](https://micropub.rocks) and [webmention.rocks](https://webmention.rocks).

- **Beyond the happy path:** it also checks the negative cases that keep people's accounts safe.
  - redirect URIs a server should never use
  - codes that work twice
  - PKCE that isn't checked
  - `state` and `iss` a client doesn't verify
  - a returned `me` a client trusts without looking
- **Results:** every result links to the specification section it checks and has a redacted HTTP transcript. Results can be published as implementation reports.
- **Structure:** follows [myindieauth.com](../myindieauth.com): plain PHP 8.2, no framework, explicit wiring in `src/Bootstrap.php`, plain templates escaped by default, one stylesheet, no inline JavaScript, MySQL and Redis.
- **Dependencies:** no Composer packages at runtime; PHPUnit is the only dev dependency.

## The test catalogs

`bin/indieauth-rocks catalog --format=md` prints both catalogs. Test numbers are permanent: new tests take unused numbers in their hundred, and retired numbers are never reused.

| Level | Meaning |
|---|---|
| Required | A MUST in the spec. Not meeting it fails the test. |
| Recommended | A SHOULD, or security best practice (RFC 9700, RFC 9207). Not meeting it is a warning. |
| Optional / Informational | Never fails. |

### Server tests: this site plays the client

| Range | Covers |
|---|---|
| 100s | Discovery and the metadata document |
| 200s | Authorization requests and responses: `state`, `iss`, client information, redirect URI checks, PKCE |
| 300s | Code redemption: single use, wrong verifier/client/redirect URI, redemption at the authorization endpoint |
| 400s | Token responses |
| 500s | Refresh tokens: rotation, reuse detection, scope |
| 600s | Revocation |
| 700s | Userinfo |
| 800s | Introspection |
| 900s | Hygiene: TLS, framing, tokens in URLs, CORS |

**Starting a run**
- Enter your profile URL on the home page. Discovery and endpoint checks run immediately.
- The run page then walks through the steps that need you:
  - **Sign in with your server.** This also signs you in here.
  - **About 14 quick "browser checks".** These are deliberately wrong requests that your server should refuse. You cancel, or report the error you saw.
  - **A few more approvals.** Each one yields a code that is presented incorrectly and then correctly.
- **Approvals needed:**
  - A correct server needs three.
  - Servers that stop accepting a code after a failed attempt, or that revoke tokens when a code is replayed, need one or two more. They're asked for only when needed.
- **Extended and slow tests** (isolated redemption checks, introspection with your own credential, an 11-minute code expiry) are opt-in.

**Cross-origin checks**
- Tests like S208 use a second hostname, `ALT_BASE_URL`, so the suite can see if a server sends someone to an unregistered redirect URI.
- The client metadata documents at `/clients/{run key}/…` are unique per run, so a server's cache can't hide whether it fetched them.

### Client tests: this site plays the server

| Range | Covers |
|---|---|
| 100s | Discovery: Link header vs HTML, relative URLs, redirects, multi-valued `rel`, legacy endpoint links, decoy servers |
| 200s | Authorization request checks, made on every request |
| 300s | Response validation: wrong/missing `state` and `iss`, error responses, unsolicited and replayed responses |
| 400s | Redemption and identity: correct redemption, differing `me` values (verified or not), spoofed profile data, token endpoint errors |
| 500s | Token use: Bearer header, refresh, rotation, scope, revocation |

**How a client run works**
- Once signed in, start a client run. Each test gets a profile URL, `/u/{run key}/{n}`, with its own test server at `/s/{run key}/{n}/…`.
- Sign in to your client with each profile. The consent screen says TEST SERVER and needs your session or the run's PIN.
- The site records what your client does (fetches, requests, redemptions). Afterwards you tell it what your client showed.
- "Must reject" tests are proven automatically if the client redeems the code it should have rejected.

### Implementation reports

- From a finished run, publish a report under a software name. The public report shows outcomes and summaries.
- HTTP transcripts stay private to the tester.

## Setup

```sh
composer install --no-dev
cp .env.example .env     # then edit it
bin/indieauth-rocks migrate
bin/indieauth-rocks doctor
```

**Settings in `.env`:**
- `BASE_URL`: the site.
- `ALT_BASE_URL`: a second hostname served by the same app, on a different origin.
- `APP_SECRET`: 64 hex characters, for encrypting tokens held during a run.
- Database and Redis settings.

**Deploying:**
- Point both hostnames at `public/`, with every non-file request going to `index.php`. The app picks its routes by `Host` header.
- Run `bin/indieauth-rocks prune` daily. It deletes unpublished runs older than `RUN_RETENTION_DAYS`.

**For local development:**
- `php -S 127.0.0.1:8095 -t public public/index.php` with `BASE_URL=http://localhost:8095/` and `ALT_BASE_URL=http://127.0.0.1:8095/` is enough for server tests.
- Client tests hand out profile URLs, which can't contain a port, so they need hostnames without ports (see the end-to-end setup below).
- `ALLOW_LOOPBACK_FETCH=1` / `ALLOW_PRIVATE_FETCH=1` let the suite reach servers on your machine or LAN. Never enable them in production.

## Safety

- **Outbound requests:** every request to a tester's server goes through `SafeFetcher`.
  - DNS is resolved and every address vetted against special-use ranges, then the connection is pinned to the vetted address.
  - It never follows redirects automatically and never downgrades from https to http.
  - Responses are size-capped and time out quickly.
- **Tokens and verifiers:** kept only in an encrypted Redis vault for the duration of a run, and revoked when it ends.
- **Transcripts:** codes, verifiers, tokens, `Authorization` and cookies are replaced with their length and a hash prefix.
- **Rate limits:** starting runs is limited per IP, and client test servers are limited per run.

## Command line

| Command | |
|---|---|
| `migrate` | Apply database migrations |
| `doctor` | Check configuration, MySQL, Redis and `APP_SECRET` |
| `catalog [--suite=server\|client] [--format=md]` | Print the test catalogs |
| `run-server <profile-url> [--driver=myindieauth] [--extended] [--slow] [--json]` | Run the server suite from the command line; exits non-zero on any failure |
| `driver:myindieauth:enroll --base=URL [--profile=URL]` | Create the account the myindieauth driver signs in with |
| `prune` | Delete old unpublished runs |

**Unattended runs:** the browser steps are pluggable (`src/Drivers/UserAgentDriver.php`), so a driver can run the whole server suite with nobody watching. `MyIndieAuthDriver` signs in to [myindieauth.com](../myindieauth.com) with a software passkey and uses its consent screen.

## End-to-end runs against myindieauth.com

`tests/e2e/myindieauth.sh` runs the full server suite over real HTTPS against a local myindieauth.com. It expects:

1. **nginx:** the vhosts in `tests/e2e/nginx-e2e.conf` (installed at `/web/conf/nginx/e2e-indieauth.conf` on the dev machine). They serve `myindieauth.pad` and three hostnames for this app (`indieauth-rocks.pad`, `redirect.indieauth-rocks.pad`, and the fixture profile host `e2e-profile.pad`). Each app gets its settings from `fastcgi_param`, so neither `.env` changes.
2. **`/etc/hosts`:** `127.0.0.1 myindieauth.pad indieauth-rocks.pad redirect.indieauth-rocks.pad e2e-profile.pad`
3. **TLS:** a local CA and certificate in `storage/e2e-tls/`, trusted by both apps through `curl.cainfo`. These commands create them:
   ```sh
   openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 3650 -subj "/CN=IndieAuth Rocks E2E CA"
   openssl req -newkey rsa:2048 -nodes -keyout pad.key -out pad.csr -subj "/CN=indieauth-rocks.pad"
   printf "subjectAltName=DNS:myindieauth.pad,DNS:indieauth-rocks.pad,DNS:redirect.indieauth-rocks.pad,DNS:e2e-profile.pad\n" > ext.cnf
   openssl x509 -req -in pad.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out pad.crt -days 825 -extfile ext.cnf
   cat /etc/ssl/certs/ca-certificates.crt ca.crt > ca-bundle.pem
   ```
4. **myindieauth database:** a `myindieauth_e2e` database, migrated with `DB_NAME=myindieauth_e2e bin/myindieauth migrate`.

On first run the script enrolls a driver account and verifies the fixture profile domain, then runs the suite. Add `--extended` for the extended tests.

## Tests

```sh
composer test
```

Integration tests run the app in-process against a disposable `indieauthrocks_test` database and Redis database 14.

- **`FakeIndieAuthServer`:** a correct IndieAuth server with switches that each break it one way. The suite must pass the correct one and catch every defect.
- **`FakeIndieAuthClient`:** does the same for the client tests.

## Layout

```
public/            index.php, app.css, copy.js
src/Bootstrap.php  services and routes for the main host, the second origin and the fixture host
src/Engine/        catalogs, outcomes, runs, the token vault
src/ServerTests/   ServerRunner, checks, probe grading, token flows
src/ClientTests/   the test server, scenarios, evidence markers, grading
src/Drivers/       unattended browser drivers (myindieauth)
src/Fetch/         SSRF-hardened HTTP, transcripts, redaction
src/IndieAuth/     discovery, me verification, profile URL and PKCE helpers
src/Storage/       PDO and Redis wrappers, repositories, migrations
templates/         plain PHP templates
tests/             unit, integration, e2e
```
