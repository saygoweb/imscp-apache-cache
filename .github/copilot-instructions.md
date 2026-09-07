# Copilot instructions for i-MSCP Apache Cache

## Project shape

This repository is an i-MSCP plugin, not a standalone PHP app with a common framework. The plugin is responsible for toggling Apache disk caching for domains and subdomains, plus WordPress-aware bypass rules.

Key pieces:

- `SGW_ApacheCache.php`: plugin entrypoint. Registers event listeners for client/reseller script startup and domain deletion cleanup.
- `backend/SGW_ApacheCache.pm`: backend engine that processes the plugin's queued work (`toadd`, `tochange`, `toenable`, `todisable`, `todelete`, `topurge`) and writes the generated Apache config snippets.
- `config.php`: cache root, cache size limit, clean interval, and default customer permission.
- `sql/001_create_apache_cache_tables.php`: defines the `apache_cache` and `apache_cache_perm` schema. The row key is `(domain_type, domain_id)` and the plugin tracks state via `apache_cache.status`.
- `frontend/`: client and reseller PHP pages that expose the feature in the i-MSCP UI.
- `themes/default/view/...`: templates for the web UI.
- `tools/` and `test/`: development-only helpers and smoke tests; they are intentionally excluded from the release archive.

## Build, test, and validation commands

No dedicated lint or standalone build system is defined in this repository beyond packaging. Validate with the project’s existing test scripts and packaging workflow.

```bash
# Package the plugin for upload into i-MSCP
make.phar package
# Produces: SGW_ApacheCache.tgz

# Backend config generator test (must run as root)
cd test/backend && sudo perl all.t

# Live vhost smoke checks against the fixture WordPress site
./test/cache-matrix.sh
./test/logged-in-leak.sh
```

To test a single live scenario, run one script directly instead of the full suite:

```bash
./test/cache-matrix.sh
# or
./test/logged-in-leak.sh
```

Development setup for the full integration environment:

```bash
# In the sibling i-MSCP checkout, bring up the Debian 13 test VM.
# If this repo lives next to the i-MSCP checkout, the path is usually:
cd ../imscp/Vagrant && vagrant up imscp_debian_trixie --provider=libvirt
# (or the equivalent path in your local layout)

# Inside the VM
sudo /usr/local/src/imscp-apache-cache/tools/setup-test-site.sh
sudo /usr/local/src/imscp-apache-cache/tools/deploy.sh
```

`deploy.sh` copies the working tree into the panel’s plugins directory instead of mounting it there, because the shared filesystem carries the host UID and the panel runs as `vu2000`.

## High-level architecture

The plugin does not build a separate application runtime. It integrates with i-MSCP’s plugin lifecycle and Apache configuration model:

- Each cacheable vhost gets an optional include file under the Apache configuration tree.
- The plugin injects an `IncludeOptional` line into the vhost’s `addons` section, so enabling or disabling the cache is a small file write or delete rather than a full vhost rebuild.
- When a listing or action changes a domain state, the plugin updates the DB row and records `status` as the queue state; the backend then performs the needed Apache config write and reload.
- Cache data is isolated per domain under `/var/cache/apache2/imscp/<fqdn>`, with a domain-specific cleaner configured via `config.php`.
- WordPress bypass logic is first-class behavior in the generated configuration, not a separate service layer: the plugin intentionally treats logged-in and stateful requests as cache exclusions.

## Conventions specific to this repo

- Keep the status machine authoritative. Changes should flow through the `apache_cache.status` values (`toadd`, `tochange`, `toenable`, `todisable`, `todelete`, `topurge`) rather than writing ad hoc config outside the backend.
- Preserve the domain model. vhosts are keyed by `domain_type` and `domain_id`, which covers `dmn`, `sub`, `als`, and `alssub`.
- Prefer writing/removing the single generated include per domain; avoid broader vhost rebuilds when only the cache toggle changes.
- Treat `enabled` and `status` as paired state. Disabled rows are intentionally left with remembered enabled state so re-enabling restores prior configuration.
- Keep generated configuration and WordPress-specific bypass behavior inside the backend or plugin templates, not in unrelated UI code.
- `tools/` and `test/` are development-only; don’t assume they are packaged or shipped.

## Working notes

- The README and plugin design are the main source of intent for behavior. The project is heavily driven by Apache+WordPress edge cases, especially around cache freshness, internal redirects, and cookie-based bypass rules.
- The repository is a product plugin for Debian 13 / Apache 2.4 and assumes i-MSCP plugin API compatibility rather than a generic PHP web app setup.
- There is no repo-local lint target or Node/Python package manager to rely on; use the shell-based verification scripts above.
