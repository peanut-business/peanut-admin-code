# Optional collaboration deployment

The default `compose.yaml` contains PHP, Nginx and MySQL. The optional
`compose.collaboration.yaml` overlay adds the application's verified HTTPS/WSS
ingress. The independent `peanut-hocuspocus` repository owns the Node program,
its lockfile, build and `docker/compose.yaml`. Node reads its configuration and
listens directly. It does not prepare or activate PHP, invoke a PHP container,
or obtain a global process activation before starting.

Node uses the application's same-origin HTTPS operation family at
`/adminapi/internal/collaboration/v1/`. Authorization sends
`{token, document_name}` with the user's original native token. PHP validates
the token, tenant, document and current permissions. Internal requests use the
configured HMAC service secret and verified TLS. Per-room business barriers,
durable saves, receipts and recovery remain part of document operations; they
do not grant a global deployment activation.

Use `collaboration-configuration.example.json` as an input template. Its `secret`
is a directly configured private string of 32–4096 UTF-8 bytes, without newline,
carriage return or NUL. Store the completed input as an ordinary, single-link
0600 file; never commit it or print it. No service-secret file or ticket-secret
file is required. The same secret is published as `COLLABORATION_SECRET` to PHP
and Node. Instance, service instance and key identities remain explicit.

Select the independent `service_root`, Compose project and registered interface
network. First copy that service's `docker/.env.example` to `docker/.env` (0600)
and assign its explicit `COLLABORATION_PORT`. Node's internal port remains 80;
the host port is an independent deployment choice. The program mount is the
service repository root, relative to its `docker/` directory; the only other
mount is its readonly `certs/` directory. There are no named volumes, application
source mounts or Node document copies. Persistence stays behind the PHP APIs.

Provide the application's `server/docker/tls/certificate.pem`, `private-key.pem`
and `ca.pem`. The private key must be 0600; the public CA must be 0444 or 0644.
The certificate must cover the configured PHP/control names and WSS hostname.
Both private origins use HTTPS on container-only port 8443; do not expose it as
a host port. Record the exact public CA SHA-256 as `ca_sha256`. Configuration
publication copies that public bundle to the application's
`private/collaboration/service/ca.pem` and the service's `certs/ca.pem`, both 0644.
The normal application permission preparation retains PHP UID 33 access. The
Node container runs as UID 33 and only needs its public CA, with readable parent
directories. No command creates a CA, disables certificate verification or
changes host/browser trust. Clients must trust the selected CA normally.

```sh
python3 server/docker/scripts/configure-collaboration.py plan --app-root /absolute/app --configuration /absolute/private-configuration.json
python3 server/docker/scripts/configure-collaboration.py enable --app-root /absolute/app --configuration /absolute/private-configuration.json --expected-revision 0
python3 server/docker/scripts/configure-collaboration.py status --app-root /absolute/app
```

These commands publish or inspect configuration only. They do not start, stop,
reload, probe, build or pull services. Backend revision/digest fields protect
the application's configuration publication with compare-and-swap; Node has no
configuration revision/digest requirement. An already enabled configuration can
only be repeated unchanged. Interrupted publication is not runtime readiness.
Plan/status report `runtime_state=not_observed`; errors never include secret
values or captured subprocess output.

The independent service uses ordinary owner-invoked Compose commands from its
own `docker/` directory: `docker compose up -d`, `docker compose ps` and
`docker compose logs`. Prepare its dependencies and build using its own locked
instructions before starting. The APP's existing `start.sh` starts only its
PHP/Nginx/MySQL composition and optional ingress. It does not start Node or make
Node's startup depend on an application deployment command.

Stopping requires protection for pending actions, unsaved rooms and unknown
save outcomes. A signal or an exited container alone is not a durable drain
receipt. This application's stop, disable and product-upgrade/recovery adapters
currently lack a controllable authenticated native shutdown receipt consumer.
For a configured enabled instance they therefore fail closed with
`COLLABORATION_NATIVE_SAFE_SHUTDOWN_UNCONFIRMED`, before stopping writers or
performing recovery. They do not fabricate a drain proof, force-kill Node,
change desired state, or recreate PHP to obtain approval. Ordinary shutdown and
upgrade of an enabled instance remain blocked until that adapter is implemented
and the original owner confirms its native safe drain. Disabling an environment
flag manually is not a shutdown proof and must not be used to bypass this limit.

Existing unresolved instances retain their original owner, saved identities,
receipts and failure budgets. Do not adopt or restart an unknown process to
manufacture a new identity. Historical migrations, stored evidence and existing
recovery snapshots remain unchanged; source changes do not establish runtime
switching or release qualification.
