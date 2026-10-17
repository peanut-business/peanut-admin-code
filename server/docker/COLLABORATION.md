# Optional collaboration lifecycle

The default `compose.yaml` remains PHP, Nginx and MySQL. The optional
`compose.collaboration.yaml` overlay owns only the application's TLS/WSS ingress.
The sibling `peanut-hocuspocus` project owns the complete Node service source,
locked dependencies, fixed runtime image, readonly program mount and independent
`docker/compose.yaml` and `docker/.env` configuration. It accesses this application solely through its
authenticated HTTPS APIs; no PHP/frontend source or database is mounted into Node.
No configure command builds or pulls images.

The managed configuration derives the public URL as
wss://<wss_hostname>:<wss_port>/collaboration, with a validated DNS/IPv4 hostname
and the explicitly registered port. It persists COLLABORATION_PUBLIC_WSS_URL in
the same atomic backend configuration revision/digest. The backend loader and
Collaboration Module consume this field; Article never reads private environment
files. Current actor-authorized ticket issuance returns wss_url, config_revision
and config_sha256 with the existing ticket/expiry/binding. Missing, malformed or
stale transport configuration refuses issuance; there is no localhost or control
origin fallback. Enable/plan/status lifecycle responses do not expose the URL.
An already enabled old configuration missing the field requires the normal
disable/new-revision enable chain, not an implicit repair or second configuration.

Use `collaboration-configuration.example.json` as the non-secret input. Its
`service_root`, `compose_project` and `network` explicitly bind the independent
service and registered interface network. The independent service uses ordinary
`docker compose up -d`, `docker compose ps` and `docker compose logs` commands
from its `docker/` directory. Its `docker/.env.example` documents all configuration fields;
program and key directory mounts use fixed repository-relative paths in Compose.
There is no service-side Python wrapper or separate docker/runtime environment file.
The deployment owner first copies that template to `docker/.env` (0600) and
assigns `COLLABORATION_PORT` to an explicitly approved host port. The container
always listens on the locked Hocuspocus default port 80; the host port maps to 80
without changing the application's `collaboration:80` upstream. No configure
command chooses a host port or switches network targets automatically.
The APP's native configuration entry publishes that same `docker/.env` and consumes that
same Compose file. PHP still authorizes activation and confirms durable drain;
the APP verifies the independent container and original durable receipt before
issuing its native Compose stop and confirming the signed stop proof.
Select
exact instance/Edition/service/key identity, the next configuration revision,
private PHP and Node HTTPS origins on container-only port 8443, the WSS hostname
and a registered host bind/port. Their hostnames become this instance's private
Nginx network aliases. The certificate must cover both private names and the
WSS hostname. Provide your own project-local `docker/tls/certificate.pem`,
`private-key.pem`, `ca.pem`, plus `private/collaboration/service/service-secret`
and `private/collaboration/ticket-secret`. Secret/key files must be ordinary,
single-link mode 0600 and service keys must contain 32–4096 bytes;
do not commit them. The loader rejects links, ambient literals and wrong owners.
The existing native permission preparation gives only PHP and Node UID 33 read
access; HTTP still has a readonly program mount. No command creates a CA or
changes host/browser trust. Clients must trust the registered CA normally.
Enable places the verified public CA at `private/collaboration/service/ca.pem`
(0644). This directory contains exactly the service key and public CA, and is
used by PHP. The independent service receives only the service key and public CA
in its own repository's sibling `certs/` directory, bound readonly as
`/run/peanut-collaboration` for Node; the ticket key remains outside Node's mount.
The service has no document data volume: persistence stays in the application's
existing database and local directory mounts through its API, with no Node-side copy.

The independent service's Compose working directory is `<service_root>/docker`,
with the readonly program mount source equal to `<service_root>`. The stop proof
binds the key directory to `<service_root>/certs`. Before
adopting this source layout for an existing instance, finish its original drain
and stop under its original owner; do not reinterpret an unresolved old container
proof or restart it to manufacture a new identity. Historical instance files and
fixed proofs remain untouched until that normal deployment handoff.

An already prepared instance with the former flat key/CA layout must first close
HTTP admission and stop its own Node/PHP/Nginx through the existing owner chain.
After adopting the program, run the same `enable` command with the original
configuration and `--expected-revision` equal to its current revision. Under the
original lock, the owner verifies the unchanged configuration binding, closes
admission, renames the original key and CA into the service directory, and CAS
publishes only the two internal file references. Material, key identity,
configuration revision/digest and lifecycle row remain unchanged. No key is
regenerated or copied. An interrupted publication is retried with the same
configuration/current revision and the one remaining key location; both old and
new keys, links or unexpected directory contents are rejected. The runtime loader
accepts only the canonical layout.

```sh
python3 server/docker/scripts/configure-collaboration.py plan --app-root /absolute/app
python3 server/docker/scripts/configure-collaboration.py enable --app-root /absolute/app --configuration /absolute/non-secret-configuration.json --expected-revision 0
server/docker/scripts/start.sh
python3 server/docker/scripts/configure-collaboration.py status --app-root /absolute/app --probe
python3 server/docker/scripts/configure-collaboration.py disable --app-root /absolute/app --drain-id original-stable-id
```

Enable publishes private profile configuration before a CAS-protected backend
commit point, with lock, file/parent fsync and rename. Existing HTTP admission is
closed through the original deployment owner before that backend publication;
enable does not reload or start services. Consumers reject revision
or digest mismatch after an interrupted write. Enable is prepare-only. Start is
the existing deployment entry, recreates PHP through its original entrypoint to
validate and publish the actual configuration binding, and requires installed
compatible migrations and real signed readiness before enabling admission. When
an original drain is pending, start restores only its exact configured Node
instance and original id/hash; it does not activate or require admission-ready
health. Continue disable with that same drain id. Without collaboration it retains
the original three-service behavior, including guided first installation.
Readiness identifies `durability_dependency=mysql` and the File reference/reservation
ledger prerequisite. It proves no external object-storage connectivity.

All responses use `peanut.collaboration.lifecycle.v1`; status without probe is
`not_probed`. No public plan/status contains secrets, URLs or shell commands.
`desired_enabled` is a boolean when the configuration was safely observed. It is
null only for a failed, unobserved configuration result: runtime is `unknown`,
`desired_configuration` is an unknown check and `configuration_unobserved` is a
gap. Native error exit codes 2 (invalid input/configuration), 3 (missing reference)
and 4 (unconfirmed runtime) retain that result. Success, prepared, ready and
disabled results always have a boolean. Safely observed errors retain the actual
configuration identity/revision/hash and any verified original drain evidence.
Disable closes the durable gate, drains every recorded Room, checks exact original
receipts, then confirms native stop before changing desired state. Unknown or
timeout outcomes retain the original id/hash/targets and desired configuration.
Retry that original id; never delete volumes or force-stop to fabricate completion.
After desired state changes to disabled, ordinary HTTP remains closed and the
response reports `restart_required=true` with no applied revision. Run the normal
start entry to refresh PHP and restore ordinary HTTP through its original owner.
Unprobed status for this configured disabled instance conservatively retains
`restart_required=true`; it does not attest that the HTTP refresh already ran.
The ordinary stop and product upgrade entries preserve desired state, and the
paired upgrade recovery point includes profile environment and instance TLS/secret
directories. Root/user operational authorization still governs actual deployment.

Record the public CA SHA-256 in `ca_sha256`; its source file must be mode 0444 or
0644. Enable copies that same verified public bundle to the shared service
directory used by PHP and Node. Only collaboration control requests select
it through the existing HTTP request contract; unrelated outbound HTTPS retains
the platform default verified CA set. No APP-wide curl/OpenSSL trust override is used.
