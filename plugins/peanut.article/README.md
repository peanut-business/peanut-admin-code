# Official Article Plugin

This directory describes the `official.article` source artifact. The selected
application profile, its actual `plugins.lock` and installation state determine
whether it is included and installed; a generated lock is not assumed empty.
Installation, tenant enablement and member permissions are separate decisions.
Use the owning application's versioned module lifecycle; do not infer a grant
from the presence of this directory.
