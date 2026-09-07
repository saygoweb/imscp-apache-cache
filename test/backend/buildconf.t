use strict;
use warnings;
use Test::More;
use Cwd 'abs_path';

# The generator is the part worth unit testing: everything downstream of it is
# Apache's behaviour, which test/cache-matrix.sh checks against a live vhost.
#
# Run as root: the module pulls in iMSCP::* from the engine, whose directory
# the panel keeps unreadable to other users.
#
#   cd test/backend && sudo perl all.t
use lib '/var/www/imscp/engine/PerlLib';

# The file is backend/SGW_ApacheCache.pm but the package is
# Plugin::SGW_ApacheCache, so it is loaded by path the way i-MSCP loads it.
require_ok(abs_path('../../backend/SGW_ApacheCache.pm'))
    or BAIL_OUT('cannot load the plugin');

# _buildConf touches no state, so it can be called on an unblessed instance
# rather than booting the whole i-MSCP backend.
my $plugin = bless {}, 'Plugin::SGW_ApacheCache';

my %base = (
    domain_name       => 'example.com',
    default_expire    => 300,
    max_expire        => 86400,
    max_file_size     => 1048576,
    ignore_no_lastmod => 1,
    debug_headers     => 1,
    static_expires    => 1,
    wordpress_mode    => 1,
    bypass_cookies    => '',
    bypass_paths      => '',
    deny_paths        => 'xmlrpc.php'
);

my $conf = $plugin->_buildConf( { %base }, '/var/cache/apache2/imscp/example.com' );

like($conf, qr/^CacheRoot\s+\Q\/var\/cache\/apache2\/imscp\/example.com\E$/m, 'cache root is per domain');
like($conf, qr/^CacheKeyBaseURL\s+"http:\/\/example\.com\/"$/m,
    'cache keys are rooted at the vhost, not the rewritten index.php');
like($conf, qr/^CacheEnable\s+disk \/$/m,        'cache is enabled at the root');
like($conf, qr/^CacheQuickHandler\s+Off$/m,      'quick handler is off so bypass rules are seen');
like($conf, qr/^CacheIgnoreNoLastMod On$/m,      'pages without validators are cacheable');
like($conf, qr/^CacheIgnoreHeaders\s+Set-Cookie$/m, 'session cookies are never stored');
like($conf, qr/^CacheDefaultExpire\s+300$/m,     'default lifetime is written through');
like($conf, qr/^Header setifempty Cache-Control "public, max-age=0, s-maxage=300" env=!imscp_nocache$/m,
    'WordPress pretty permalinks get explicit shared-cache freshness without forcing a browser HTML cache');

my $migration = do { local $/; open my $fh, '<', abs_path('../../sql/002_add_deny_paths.php') or die $!; <$fh> };
like($migration, qr/ADD\s+(?:COLUMN\s+)?IF\s+NOT\s+EXISTS\s+`?deny_paths`?/i,
    'upgrade migration is idempotent and does not re-add an existing deny_paths column');
like($migration, qr/WHERE\s+`?deny_paths`?\s+IS\s+NULL\s+OR\s+`?deny_paths`?\s*=\s*''/i,
    'upgrade migration preserves any existing deny_paths values instead of clobbering them');

# The pair that makes a bypass actually bypass.
like($conf, qr/^SetEnvIf imscp_nocache \. no-cache$/m,
    'a bypassed response is not stored');
like($conf, qr/^RequestHeader set Cache-Control "no-cache" env=imscp_nocache$/m,
    'a bypassed request is not served from cache');

# Cookies must go through mod_setenvif: an ap_expr on %{HTTP_COOKIE} would make
# Apache add Vary: Cookie and key every entry on the visitor's cookie jar.
like($conf, qr/^SetEnvIfNoCase Cookie "[^"]*wordpress_logged_in_/m,
    'WordPress session cookies bypass');
unlike($conf, qr/HTTP_COOKIE/,
    'no ap_expr on HTTP_COOKIE, which would poison Vary');

# Paths must be matched on the original request line, which survives the
# internal redirect that a pretty permalink triggers.
unlike($conf, qr/^CacheDisable/m,
    'no CacheDisable, which a rewritten URL would slip past');
like($conf, qr{^<IfModule mod_rewrite\.c>\n\s*RewriteEngine On\n\s*RewriteCond %\{REQUEST_FILENAME\} !-f\n\s*RewriteCond %\{REQUEST_FILENAME\} !-d\n\s*RewriteCond %\{QUERY_STRING\} !__imscp_cache_key=\n\s*RewriteCond %\{REQUEST_URI\} !\^/index\\\.php\$\n\s*RewriteRule \^ %\{REQUEST_URI\}\?__imscp_cache_key=%\{REQUEST_URI\} \[L,QSA,NE\]$}m,
    'pretty permalinks keep a distinct synthetic cache key before the front-controller rewrite');

# Names reach the config quotemeta-escaped, so assertions about which names are
# present read the escapes back out rather than spelling them out.
my $unescaped = $conf =~ s/\\(?=\W)//gr;

like($unescaped, qr{^SetEnvIfExpr "%\{THE_REQUEST\}[^"]*/wp-admin}m,
    'WordPress paths bypass, matched on the request line');
like($unescaped, qr{/wp-json},      'the REST endpoint bypasses');
like($unescaped, qr{/xmlrpc\.php},  'XML-RPC bypasses');
like($unescaped, qr{/\.well-known/}, 'ACME challenges bypass');
like($conf, qr/REQUEST_METHOD/, 'non-GET methods bypass');
like($conf, qr/QUERY_STRING/,  'searches and previews bypass');
like($conf, qr/mod_expires/,   'static assets get a browser lifetime');

# A denied path is refused before the cache is reached, which needs a section
# that merges after the vhost's <Directory> block and the customer's .htaccess.
like($unescaped, qr{^<LocationMatch "\(\?:[^"]*xmlrpc\.php[^"]*\">$}m,
    'a denied path becomes a LocationMatch on any part of the path');
like($conf, qr{^<LocationMatch[^\n]*\n\s+Require all denied\n</LocationMatch>$}m,
    'the denied path is refused outright');
# Unanchored, so the pattern covers /xmlrpc.php and //xmlrpc.php alike.
unlike($conf, qr{<LocationMatch "\(\?:\^}, 'the deny pattern is not anchored');

# WordPress mode off: only the customer's own entries remain.
my $plain = $plugin->_buildConf(
    { %base, wordpress_mode => 0, static_expires => 0, debug_headers => 0,
      bypass_cookies => "my_session\nother", bypass_paths => '/private',
      deny_paths => '' },
    '/tmp/c'
);
unlike($plain, qr/wp-admin/,       'no WordPress paths when the mode is off');
unlike($plain, qr/wordpress_logged_in_/, 'no WordPress cookies when the mode is off');
unlike($plain, qr/QUERY_STRING/,   'no search bypass when the mode is off');
unlike($plain, qr/s-maxage/,       'no synthetic shared-cache freshness when the mode is off');
unlike($plain, qr/mod_expires/,    'no expiry block when static expiry is off');
unlike($plain, qr/LocationMatch/,  'nothing is refused when the deny list is empty');
unlike($plain, qr/CacheHeader/,    'no diagnostic headers when they are off');
unlike($plain, qr/X-Imscp-Bypass/, 'no bypass header when diagnostics are off');
like($plain, qr/my_session\|other/, 'custom cookies are kept');
like($plain, qr/private/,           'custom paths are kept');
# Still non-GET, always.
like($plain, qr/REQUEST_METHOD/,    'non-GET bypass is not optional');

# A comma separated deny list, as the help text describes it.
my $denies = $plugin->_buildConf(
    { %base, wordpress_mode => 0, deny_paths => 'xmlrpc.php, /wp-json , xmlrpc.php' },
    '/tmp/c'
);
like($denies, qr{\Q<LocationMatch "(?:xmlrpc\.php|\/wp\-json)">\E},
    'a comma separated deny list is split, trimmed, escaped and deduplicated');

# A cookie name is user supplied, so it must not be able to smuggle in a regex.
my $meta = $plugin->_buildConf(
    { %base, wordpress_mode => 0, bypass_cookies => 'a.*b', deny_paths => 'c.*d' },
    '/tmp/c'
);
like($meta, qr/\Qa\.\*b\E/, 'regex metacharacters in a cookie name are escaped');
like($meta, qr/\Qc\.\*d\E/, 'regex metacharacters in a denied path are escaped');

done_testing();
