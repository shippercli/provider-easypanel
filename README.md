# Shipper CLI EasyPanel Provider

Deploy and operate applications on an EasyPanel instance through its API.

## Version 1 scope

Supported:

- Create or reuse deterministic Shipper-managed projects and app services
- Container image sources
- Public Git sources and EasyPanel GitHub integration sources
- Inline Dockerfile sources
- Environment variables
- Custom domains with EasyPanel-managed HTTPS
- MySQL, MariaDB, PostgreSQL, MongoDB, and Redis service lifecycle
- Queue workers as separately managed app services
- Cron jobs as scheduled scripts in a managed Box service when the source is Git-backed
- App deployment
- Service log queries (when EasyPanel log aggregation is enabled)
- Ownership-guarded service and empty-project cleanup, including core orphan-preview cleanup integration

Not yet supported by the provider:

- Rollback
- Daemon/process supervision
- Resource limits, mounts, and persistent-volume lifecycle

EasyPanel may provide those capabilities in its UI, but this package does not claim support until their API behavior and cleanup semantics have dedicated tests.

## Database connection variables

A single newly created database supplies `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`,
and `DB_PASSWORD` to the application. Multiple database declarations require an
explicit unique prefix for every database name in the provider configuration:

```yaml
providers:
  easypanel:
    database_environment_prefixes:
      main: DB
      analytics: ANALYTICS_DB
```

The `analytics` database then supplies `ANALYTICS_DB_HOST`,
`ANALYTICS_DB_DATABASE`, `ANALYTICS_DB_USERNAME`, and `ANALYTICS_DB_PASSWORD`.
Configure the application to read these variables. Prefixes must be uppercase
environment identifiers and cannot be shared by databases. Passwords are
selected from the merged application `<PREFIX>_PASSWORD` when present (for
example `DB_PASSWORD`), then shared `database_password`, then independent
generation. The chosen password is sent unchanged to creation and application
delivery. Profile environment values override project/provider values; existing
application values can also supply the password when recreating a missing
service. Empty application passwords and malformed application environment content fail
before database provisioning. Database names/users and host values remain
controlled by declarations/provider settings.
Re-apply preserves the existing application environment and does not rotate
passwords for existing database services. An existing service's credentials
must already be configured in the application or supplied through its configured
environment. Reuse checks the matching database name and username, a host, and a
nonempty password before deployment. Malformed or incomplete environment content
fails without rotating the database password. The provider cannot recover a
previously discarded password.

## Install

```bash
composer require shippercli/provider-easypanel
```

## Configure

Keep the API token in an environment variable.

```yaml
providers:
  easypanel:
    url: "https://panel.example.com"
    auth_token: "${EASYPANEL_AUTH_TOKEN}"
    managed_prefix: "shipper-"
    service_name: "app"
    source:
      type: image
      image: "ghcr.io/acme/backend:latest"

projects:
  backend:
    provider: easypanel
    profiles:
      production:
        branch: main
        domain: "api.example.com"
        env:
          APP_ENV: production
```

The resulting EasyPanel project is named `shipper-backend-production`. If no profile domain is supplied, EasyPanel's generated HTTPS domain remains available.

## Source types

### Container image

```yaml
source:
  type: image
  image: "nginx:alpine"
```

### Public Git repository

```yaml
source:
  type: git
  repo: "https://github.com/acme/backend.git"
  ref: main
  path: "/"
```

When `source` is omitted, GitHub, GitLab, and Bitbucket repository metadata from the Shipper project is converted to a public Git URL.

### EasyPanel GitHub integration

```yaml
source:
  type: github
  owner: acme
  repo: backend
  ref: main
  path: "/"
```

The repository must be accessible to the GitHub integration configured in EasyPanel.

### Inline Dockerfile

```yaml
source:
  type: dockerfile
  dockerfile: |
    FROM nginx:alpine
    COPY . /usr/share/nginx/html
```

## Cleanup safety

`shipper destroy` refuses to delete a service unless all of these conditions hold:

- The project name is derived from the configured `managed_prefix`, project, and profile.
- The exact service name matches the provider configuration.
- The service is an app service.
- The service environment contains both Shipper ownership markers.

After deleting the marked service, the provider deletes its project only when `destroy_project` is enabled and no services remain. Set `destroy_project: false` to retain empty managed projects. The core orphan-preview flow enumerates marked app services through their configured domains and routes deletion through the same ownership checks.

## Project ownership scope

Managed project names append the first 20 hexadecimal characters of the SHA-256
hash of the JSON-encoded `[project-name, profile-name]` pair (PHP `json_encode`
with `JSON_THROW_ON_ERROR`). This distinguishes names that normalize to the same
slug or have ambiguous hyphen boundaries. Ownership markers use the complete
scoped project name.

Legacy names without this suffix require manual review and migration. Apply
refuses to create a replacement while the legacy project exists. Destroy refuses
a legacy target when no scoped project exists; preview listing excludes legacy
targets. Verify the exact project/profile, services, secrets, and database
associations before renaming the project and updating ownership markers on all
app, database, worker, and scheduler services. Shipper does not automatically
adopt or migrate legacy projects.

## Development

```bash
composer install
composer test
```

### Plan ownership inspection

Plan validates configuration and reads EasyPanel project/service inventory. It
requires API access and performs no writes. Legacy project names, occupied app
names of another service type, missing or malformed ownership markers, and API
read failures return `blocked: true` with one actionable blocked entry. The core
CLI enforcement is proposed in shippercli/cli#154. Database and workload
prerequisites remain subject to validation during apply; an unblocked app plan
does not prove every deployment prerequisite or live connection is ready.

### HTTP response and diagnostic boundaries

Authenticated EasyPanel requests reject every non-2xx HTTP status before decoding
the response body. Redirects are not followed; success-shaped JSON on a redirect
is not accepted as an API result. HTTP failures report their status code without
including server messages or validation payloads, which may echo submitted
credentials. Transport failures report a cURL error number without raw URL/error
text. Use the server's protected diagnostics to investigate detailed validation
failures; application logs and successful API data are not automatically redacted.

`python3 tests/http/test_responses.py` exercises the real cURL path against a
loopback server using synthetic credentials. It covers 301/302/303/307/308,
400/401/403/404/422/429/500/502, JSON/text error bodies, successful direct/nested
responses, and malformed-URL diagnostics. The pre-fix code accepted a 301 with
success-shaped JSON and leaked synthetic credentials echoed in a 400 response.
The check runs in PHP matrix CI. It does not prove live EasyPanel compatibility.


### Distribution archive checks

Composer archive exclusions and Git export attributes omit installed dependencies,
tests, workflow files, root environment/auth configuration, and PHPUnit caches and
configuration. Runtime source, package metadata, documentation, and assets remain
available. `python3 tests/package/verify-dist.py` checks Composer and Git exports,
then loads all declared runtime classes from an extracted ZIP with an overridden
class map. Dependency classes come from the installed checkout; this check does
not perform a fresh production dependency install or live deployment. CI runs it
with the existing provider tests. Git export verification uses current worktree
attributes; committed attributes determine subsequent GitHub source archives.

### Inventory identity validation

Project/service discovery rejects duplicate project names, repeated service
names within a project (even with different service types), and services whose
parent project is absent from the returned project list. Project names, service
names, parent names and types must be nonempty trimmed strings. A service name
can still occur in different projects. The full inventory is validated before
it is returned, so matching an early entry cannot hide a later ambiguity. These
checks do not establish a snapshot, independent ownership, or concurrency locks.
The HTTP fixture exercises direct and nested API envelopes with synthetic data;
live EasyPanel compatibility remains unverified.

### Response envelope validation

Successful HTTP status is insufficient: the decoder requires exactly one direct
`json` or nested `result.data.json` success payload and rejects explicit envelope
errors, unsuccessful flags, missing envelopes and ambiguous dual envelopes.
Explicit null payloads remain supported for mutation acknowledgements. Invalid
JSON and API failures use fixed messages with no chained payload exception.
For project/service inventories, raw JSON collections must be arrays and entries
objects before associative decoding can erase that distinction. Custom test
transports still return decoded payloads under their existing contract.

An acknowledgement does not prove provisioning, runtime health or deletion.
Rejected mutation responses can follow a remote side effect; inspect the account
before retrying. Live API compatibility and workflow completion remain unverified.

Service inspections reject null/scalar/list payloads instead of converting them
to empty details. Domain inventories require a list of object records with
nonempty trimmed host strings; malformed data cannot become an empty list that
invites duplicate creation. Empty domain lists remain valid, and multiple paths
on one host are preserved. Raw HTTP object/array distinctions are checked before
normalization. These are shape checks: ownership markers, expected identities,
requested configuration, runtime state and live compatibility require separate
verification.

Inspection responses also reject contradictory optional `projectName`,
`serviceName`, `name` or `type` fields. When present, each must exactly match the
requested project, service and canonical service type. Diagnostics do not echo
the returned values. Responses omitting these fields remain supported, so this
is contradiction detection rather than independent proof of target identity.
Ownership environment markers and live verification are still required.

Database creation payloads cannot override the explicit project/service arguments.
Conflicting or malformed `projectName`/`serviceName` payload fields are rejected
before sending a request; matching duplicates and other database options remain
supported. The provider's existing internal payload already supplies fixed
targets; this guard also protects direct client use. It does not validate every
database option or prove the resulting database is usable.

Cleanup verifies a fresh inventory after deleting the main app, even when
`destroy_project: false`. A still-visible app or unreadable inventory stops the
flow. If project cleanup is enabled and no services remain, a second fresh read
must show the project absent after its deletion request. Other services are
preserved and prevent project deletion. Each verification makes one observation
and sends no repeated delete; asynchronous work may return an unconfirmed result
requiring a status check before retry. This proves observed API inventory
absence, not physical erasure, worker/database completion, or transactional
cleanup. Live account verification remains required.

Owned worker, Box scheduler and database deletion now each require a fresh
validated inventory showing the project/service name absent before reconciliation
or cleanup continues. A replacement under the same name, even with a different
type, is not treated as absence. Unreadable or still-visible results stop the
operation with an unconfirmed result. Verification performs one read and no
repeat delete; asynchronous completion may require a later status check. This
adds observation-time completion checks and does not provide shared locking,
physical erasure guarantees or transactional cleanup.

### Service name conflicts

The application `service_name` must differ from every declared database service
(`db-` plus its normalized name) and active queue worker (`worker-` plus its
normalized name). The fixed `scheduler` service name is reserved, including when
no cron jobs are declared, because scheduler reconciliation inspects that name.
Active queue names and cron script names must also remain distinct after
lowercasing, punctuation normalization and truncation to 48 characters.
Configuration validation rejects these conflicts before deployment API calls.

### Mixed-case database identifiers

Database names and usernames are lowercased before unsupported characters are
replaced with underscores. For example, `Main_DB` becomes `main_db` and `AppUser`
becomes `appuser`. Earlier code replaced uppercase letters instead, producing
`ain` and `pp_ser`. Existing services retain the same normalized service name;
reapply refuses mismatching application credentials rather than renaming or
recreating the database. Review actual database/user names before upgrading an
existing mixed-case declaration. Preserve its data and credentials and plan an
explicit migration; changing a declaration can change the targeted service.
