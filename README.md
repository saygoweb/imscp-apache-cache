# i-MSCP Apache Cache Plugin

Gives every domain, subdomain, alias and alias subdomain a switch that turns on
the Apache disk cache (`mod_cache_disk`) for that vhost, with a WordPress mode
that keeps signed-in visitors, comment authors and shopping carts out of the
cache.

See [CHANGELOG](CHANGELOG.md) for what has changed in each version.

## Requirements

* i-MSCP 1.5.x (plugin API 1.5.1)
* Apache 2.4 with `mod_cache`, `mod_cache_disk`, `mod_expires`, `mod_headers`
  and `mod_setenvif` available, and `htcacheclean` from `apache2-utils`
* Debian 13 (Trixie). Developed and tested against Apache 2.4.68.

The plugin enables the Apache modules it needs at install time.

## Installation

1. Upload `SGW_ApacheCache.tgz` through the plugin management interface
2. Install the plugin through the plugin management interface

Installing marks every vhost for rebuild once, so that each gets the
`IncludeOptional` line the plugin hangs its configuration off. After that,
switching the cache on or off for a domain only writes or removes one small
file and reloads Apache.

## What a customer sees

Under **Domains / Apache Cache**, one row per vhost with an enable switch, a
purge button, and an edit page carrying:

| Setting | Meaning |
| --- | --- |
| WordPress mode | Bypass the admin area, login, cron, REST and XML-RPC endpoints, searches, non-GET requests, and any visitor carrying a WordPress, WooCommerce or comment-author cookie |
| Cache pages with no freshness information | Required for WordPress; see below |
| Give static files a browser lifetime | `mod_expires` for images, fonts, CSS and JavaScript |
| Send diagnostic headers | `X-Cache: HIT`/`MISS` and `X-Imscp-Bypass` |
| Default and maximum lifetime | How long a page is kept |
| Largest response to cache | Responses above this are not stored |
| Additional cookies / paths | Customer's own bypass lists |
| Paths refused outright | Comma separated path fragments answered with 403 Forbidden instead of being served; `xmlrpc.php` by default |

A reseller gets **Customers / Apache Cache**, which grants or withdraws the
feature per customer and switches the cache on or off across all of a
customer's domains at once. Withdrawing the feature also disables the cache on
that customer's domains, so the feature and its effects go away together.

## Why the configuration looks the way it does

A handful of things about WordPress and Apache drive the whole design, and each
was measured rather than assumed.

**WordPress HTML carries no freshness information.** No `Cache-Control`, no
`Expires`, no `Last-Modified`, no `ETag`. Apache will not store such a response
unless `CacheIgnoreNoLastMod` is on, so without it the cache does nothing at
all. That is why the setting exists and why it defaults to on.

**WordPress does not send `Vary: Cookie`.** An anonymous and a signed-in
response are byte-for-byte indistinguishable to a cache. Nothing but an
explicit bypass keeps an administrator's page out of the shared cache.

**Blocking a bypass needs two directives, not one.** The `no-cache` environment
variable stops a response being *stored*; it does not stop a stored response
being *served*. A request-side `Cache-Control: no-cache`, set with
`RequestHeader`, stops the serving. Only the pair both keeps a signed-in
visitor's page out of the cache and keeps that visitor off a stale anonymous
page. Both need `CacheQuickHandler Off`, or the cache answers before the bypass
rules have been evaluated.

**Cookie matching must not go through an expression.** Referencing an `HTTP_*`
variable in an `ap_expr` — `SetEnvIfExpr "%{HTTP_COOKIE} =~ ..."` — makes
Apache append that header to the response's `Vary`. The cache would then key
every entry on the visitor's entire cookie jar, and on any real site with
analytics cookies the hit rate would collapse to nothing. Cookies therefore go
through `SetEnvIfNoCase Cookie`, which has no such side effect.

**Path matching must survive the internal redirect.** WordPress's `.htaccess`
rewrites every pretty permalink to `/index.php`, and that rewrite is an
internal redirect: the request is reprocessed, so anything keyed on the URL now
sees `/index.php`, and the environment set on the first pass has been renamed
out of the way with a `REDIRECT_` prefix. `CacheDisable /my-account` and
`SetEnvIfExpr %{REQUEST_URI}` both silently stop working, while still appearing
to work for `/wp-admin`, which is a real directory. Paths are therefore matched
against `THE_REQUEST`, the original request line, which survives intact.

**The cache key must survive the same rewrite.** A front-controller request is
still a unique page even when Apache has rewritten it to `/index.php`, so the
plugin adds a synthetic `__imscp_cache_key` query parameter based on the
original `REQUEST_URI` before the rewrite. That keeps distinct pretty URLs from
collapsing onto the same disk cache entry while leaving static assets and real
directories alone.

**A refused path is refused, not merely uncached.** The deny list is a
`<LocationMatch>` carrying `Require all denied`. Location sections merge after
`<Directory>` blocks and after the customer's `.htaccess`, so the refusal wins
over whatever those grant, and the access check runs before both the cache and
the per-directory rewrite that turns a pretty permalink into `/index.php`. The
pattern is unanchored, so `xmlrpc.php` also covers `/xmlrpc.php` and the
`//xmlrpc.php` form scanners like to use. Entries are `quotemeta`-escaped, so a
customer cannot smuggle a regex in. The snippet only exists while the cache is
enabled for the domain, so the deny list goes away with it.

## Cache layout

Each vhost gets its own cache root under `/var/cache/apache2/imscp/<fqdn>`, so
purging one domain is a directory removal and cannot disturb another. Debian's
`apache-htcacheclean` unit takes a single path and cannot express that, so the
plugin installs its own `imscp-htcacheclean` timer that runs one pass per
domain cache. Both the root and the size limit are in `config.php`.

## Development

The `tools/` and `test/` directories are development-only and are excluded from
the release archive.

```shell
# In the i-MSCP repository, bring up the Debian 13 box:
cd imscp/Vagrant && vagrant up imscp_debian_trixie --provider=libvirt

# Inside the box: create the fixture WordPress site, then deploy the plugin
sudo /usr/local/src/imscp-apache-cache/tools/setup-test-site.sh
sudo /usr/local/src/imscp-apache-cache/tools/deploy.sh
```

`deploy.sh` copies the working tree into the panel's plugins directory rather
than mounting it there, because the virtiofs share carries the host's uid and
the panel runs as `vu2000`. It restarts `imscp_panel` afterwards, since that
pool's opcache would otherwise keep serving the previous version of a file.

Then, in the panel: *Settings / Plugins*, **Synchronize**, and install.

Tests:

```shell
# Configuration generator, no live Apache needed. Must run as root: the module
# pulls in iMSCP::* from the engine, whose directory is not world readable.
cd test/backend && sudo perl all.t

# Behaviour of a live vhost, against the fixture WordPress site
test/cache-matrix.sh
test/logged-in-leak.sh
```

## Packaging

```shell
make.phar package    # produces SGW_ApacheCache.tgz
```

Only `tar.gz`, `tar.bz2`, `tar.xz` and `zip` archives are accepted by the
plugin uploader. Do not upload a Git source archive.

## How to Help

Report issues in the
[GitHub issue tracker](https://github.com/saygoweb/imscp-apache-cache/issues),
with as much detail as you can. Pull requests are welcome.

## License

```
i-MSCP SGW_ApacheCache plugin
Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

## Authors

* Cambell Prince <cambell.prince@gmail.com>
