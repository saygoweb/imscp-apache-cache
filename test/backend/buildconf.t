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
    bypass_paths      => ''
);

my $conf = $plugin->_buildConf( { %base }, '/var/cache/apache2/imscp/example.com' );

like($conf, qr/^CacheRoot\s+\Q\/var\/cache\/apache2\/imscp\/example.com\E$/m, 'cache root is per domain');
like($conf, qr/^CacheEnable\s+disk \/$/m,        'cache is enabled at the root');
like($conf, qr/^CacheQuickHandler\s+Off$/m,      'quick handler is off so bypass rules are seen');
like($conf, qr/^CacheIgnoreNoLastMod On$/m,      'pages without validators are cacheable');
like($conf, qr/^CacheIgnoreHeaders\s+Set-Cookie$/m, 'session cookies are never stored');
like($conf, qr/^CacheDefaultExpire\s+300$/m,     'default lifetime is written through');

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

# WordPress mode off: only the customer's own entries remain.
my $plain = $plugin->_buildConf(
    { %base, wordpress_mode => 0, static_expires => 0, debug_headers => 0,
      bypass_cookies => "my_session\nother", bypass_paths => '/private' },
    '/tmp/c'
);
unlike($plain, qr/wp-admin/,       'no WordPress paths when the mode is off');
unlike($plain, qr/wordpress_logged_in_/, 'no WordPress cookies when the mode is off');
unlike($plain, qr/QUERY_STRING/,   'no search bypass when the mode is off');
unlike($plain, qr/mod_expires/,    'no expiry block when static expiry is off');
unlike($plain, qr/CacheHeader/,    'no diagnostic headers when they are off');
unlike($plain, qr/X-Imscp-Bypass/, 'no bypass header when diagnostics are off');
like($plain, qr/my_session\|other/, 'custom cookies are kept');
like($plain, qr/private/,           'custom paths are kept');
# Still non-GET, always.
like($plain, qr/REQUEST_METHOD/,    'non-GET bypass is not optional');

# A cookie name is user supplied, so it must not be able to smuggle in a regex.
my $meta = $plugin->_buildConf(
    { %base, wordpress_mode => 0, bypass_cookies => 'a.*b' }, '/tmp/c'
);
like($meta, qr/\Qa\.\*b\E/, 'regex metacharacters in a cookie name are escaped');

done_testing();
