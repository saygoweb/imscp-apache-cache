# Changelog

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
