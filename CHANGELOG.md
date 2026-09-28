# Changelog

## 0.3.0 (unreleased)

Added GraphQL support, through the SGW_GraphQL plugin's extension hook.

* `apacheCache` on `Domain`, `Subdomain` and `DomainAlias`: every setting plus
  the plugin's own row status, so a client can poll until a change settles.
  Reads as the plugin's defaults, not null, for a vhost never configured.
* `apacheCacheUpdate(input: ...)`: a partial update - only the fields sent are
  changed - with the same validation the edit page enforces.
* `apacheCachePurge(id: ...)`: schedules the cache to be emptied.
* No change if SGW_GraphQL is not installed: the extension is only ever loaded
  from a single listener this plugin adds for that purpose.

## 0.2.0 (unreleased)
Added deny paths e.g. for xmlrpc.php

* Per-domain list of paths refused outright with 403 Forbidden, matched on any
  part of the URL path and defaulting to `xmlrpc.php`.

## 0.1.0 (unreleased)

First working version.

* Per-vhost switch for the Apache disk cache, covering domains, subdomains,
  aliases and alias subdomains.
* WordPress mode: keeps the admin area, login, cron, REST and XML-RPC
  endpoints, searches, non-GET requests and any visitor carrying a WordPress,
  WooCommerce or comment-author cookie out of the cache.
* Per-domain cache lifetime, maximum response size and extra cookie/path
  bypass lists.
* Per-domain purge.
* Reseller page to grant or withdraw the feature per customer, and to switch
  the cache on or off across all of a customer's domains at once.
* Own `htcacheclean` timer, one pass per domain cache.
* Clean install, disable, re-enable and uninstall cycles: disabling the plugin
  removes every generated file but remembers which domains were on, and
  uninstalling takes the vhost include lines with it.
