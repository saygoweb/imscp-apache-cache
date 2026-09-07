=head1 NAME

 Plugin::SGW_ApacheCache

=cut

# i-MSCP SGW_ApacheCache plugin
# Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.

package Plugin::SGW_ApacheCache;

use strict;
use warnings;
use iMSCP::Database;
use iMSCP::Debug;
use iMSCP::Dir;
use iMSCP::EventManager;
use iMSCP::Execute;
use iMSCP::File;
use iMSCP::Service;
use iMSCP::TemplateParser;
use Servers::httpd;
use parent 'Common::SingletonClass';

=head1 DESCRIPTION

 Backend for the i-MSCP SGW_ApacheCache plugin.

 Each cacheable vhost gets a generated snippet under CONF_DIR, pulled in by an
 IncludeOptional line that this plugin injects into the vhost's `addons`
 section. Because the include is optional and present on every vhost, turning
 the cache on or off for a domain only writes or removes that one file and
 reloads Apache; it never needs the vhost itself to be rebuilt.

=head1 PUBLIC METHODS

=over 4

=item install( )

 Perform install tasks

 Return int 0 on success, other on failure

=cut

sub install
{
    my ($self) = @_;

    my $rs = $self->_checkRequirements();
    $rs ||= $self->_enableApacheModules();
    $rs ||= $self->_installCleaner();
    return $rs if $rs;

    # Existing vhosts predate the IncludeOptional line, so they have to be
    # rebuilt once for the plugin to be able to reach them at all.
    $self->_rebuildAllVhosts();
}

=item uninstall( )

 Perform uninstall tasks

 Return int 0 on success, other on failure

=cut

sub uninstall
{
    my ($self) = @_;

    my $rs = $self->_removeCleaner();
    return $rs if $rs;

    # Drop every generated snippet and its cache directory.
    for my $domain ( @{ $self->_configuredDomains() } ) {
        $rs ||= $self->_removeDomain( $domain );
    }
    return $rs if $rs;

    # Strip the IncludeOptional line back out of the vhosts.
    $rs = $self->_rebuildAllVhosts();
    $rs ||= $self->_reloadHttpd();
    $rs;
}

=item update( $fromVersion, $toVersion )

 Perform update tasks

 Return int 0 on success, other on failure

=cut

sub update
{
    my ($self) = @_;

    my $rs = $self->_enableApacheModules();
    $rs ||= $self->_installCleaner();
    return $rs if $rs;

    # Regenerate every snippet so that changes to the generator take effect.
    $self->{'db'}->doQuery(
        'u', "UPDATE apache_cache SET status = 'tochange' WHERE status = 'ok'"
    );
    0;
}

=item enable( )

 Perform enable tasks

 Return int 0 on success, other on failure

=cut

sub enable
{
    my ($self) = @_;

    my $rs = $self->{'db'}->doQuery(
        'u',
        "UPDATE apache_cache SET status = 'toenable' WHERE enabled = 1 AND status = 'disabled'"
    );
    unless ( ref $rs eq 'HASH' ) {
        error( $rs );
        return 1;
    }

    # Process them here rather than leaving them queued: nothing else runs in
    # this pass, so the caches would otherwise stay pending until some
    # unrelated backend request came along.
    $self->run();
}

=item disable( )

 Perform disable tasks

 All snippets are removed, but each row remembers whether it was enabled so
 that re-enabling the plugin restores the previous state.

 Return int 0 on success, other on failure

=cut

sub disable
{
    my ($self) = @_;

    my $rs = $self->{'db'}->doQuery(
        'u',
        "UPDATE apache_cache SET status = 'todisable' WHERE status <> 'disabled'"
    );
    unless ( ref $rs eq 'HASH' ) {
        error( $rs );
        return 1;
    }

    $self->run();
}

=item run( )

 Process pending items

 Return int 0 on success, other on failure

=cut

sub run
{
    my ($self) = @_;

    my $rows = $self->{'db'}->doQuery(
        'apache_cache_id',
        "
            SELECT * FROM apache_cache
            WHERE status IN('toadd', 'tochange', 'toenable', 'todisable', 'todelete', 'topurge')
        "
    );
    unless ( ref $rows eq 'HASH' ) {
        error( $rows );
        return 1;
    }

    return 0 unless %{ $rows };

    my $ret = 0;
    my $changed = 0;

    for my $row ( values %{ $rows } ) {
        my $status = $row->{'status'};
        my ( $rs, @sql );

        if ( $status eq 'todelete' ) {
            $rs = $self->_removeDomain( $row->{'domain_name'} );
            @sql = $rs
                ? ( 'UPDATE apache_cache SET status = ? WHERE apache_cache_id = ?',
                    ( scalar getMessageByType( 'error' ) || 'Unknown error' ),
                    $row->{'apache_cache_id'} )
                : ( 'DELETE FROM apache_cache WHERE apache_cache_id = ?',
                    $row->{'apache_cache_id'} );
        } elsif ( $status eq 'topurge' ) {
            $rs = $self->_purgeDomain( $row->{'domain_name'} );
            @sql = (
                'UPDATE apache_cache SET status = ?, state = ? WHERE apache_cache_id = ?',
                ( $rs ? ( scalar getMessageByType( 'error' ) || 'Unknown error' )
                      : ( $row->{'enabled'} ? 'ok' : 'disabled' ) ),
                ( $rs ? '' : 'Cache purged' ),
                $row->{'apache_cache_id'}
            );
        } elsif ( $status eq 'todisable' || !$row->{'enabled'} ) {
            $rs = $self->_removeDomain( $row->{'domain_name'} );
            @sql = (
                'UPDATE apache_cache SET status = ? WHERE apache_cache_id = ?',
                ( $rs ? ( scalar getMessageByType( 'error' ) || 'Unknown error' ) : 'disabled' ),
                $row->{'apache_cache_id'}
            );
        } else {
            $rs = $self->_writeDomain( $row );
            @sql = (
                'UPDATE apache_cache SET status = ?, state = ? WHERE apache_cache_id = ?',
                ( $rs ? ( scalar getMessageByType( 'error' ) || 'Unknown error' ) : 'ok' ),
                ( $rs ? '' : '' ),
                $row->{'apache_cache_id'}
            );
        }

        $ret ||= $rs;
        $changed = 1;

        my $qrs = $self->{'db'}->doQuery( 'dummy', @sql );
        unless ( ref $qrs eq 'HASH' ) {
            error( $qrs );
            return 1;
        }
    }

    $ret ||= $self->_reloadHttpd() if $changed;
    $ret;
}

=back

=head1 PRIVATE METHODS

=over 4

=item _init( )

 Initialize plugin

 Return Plugin::SGW_ApacheCache

=cut

sub _init
{
    my ($self) = @_;

    $self->{'db'} = iMSCP::Database->factory();
    $self->{'httpd'} = Servers::httpd->factory();

    $self->{'cacheRoot'} = $self->{'config'}->{'cache_root'} || '/var/cache/apache2/imscp';
    $self->{'sizeLimit'} = $self->{'config'}->{'cache_size_limit'} || '256M';
    $self->{'cleanInterval'} = $self->{'config'}->{'clean_interval'} || '30min';

    # Generated snippets live beside the per-domain custom configuration that
    # i-MSCP already maintains, but in their own directory so that nothing here
    # can collide with a file the customer owns.
    $self->{'confDir'} = $self->{'httpd'}->{'config'}->{'HTTPD_CONF_DIR'} . '/imscp/cache';

    iMSCP::EventManager->getInstance()->register(
        'afterHttpdBuildConf', sub { $self->_onAfterHttpdBuildConf( @_ ); }
    );

    $self;
}

=item _onAfterHttpdBuildConf( \$cfgTpl, $filename, \%data )

 Inject the IncludeOptional line into the vhost's addons section.

 The line goes into every live vhost, whether or not the domain currently has
 caching switched on, so that toggling the switch later needs no rebuild.

 Return int 0

=cut

sub _onAfterHttpdBuildConf
{
    my ($self, $cfgTpl, $filename, $data) = @_;

    return 0 unless $filename eq 'domain.tpl';

    # uninstall() rebuilds every vhost precisely to drop this line, and that
    # rebuild happens in the same pass, with this listener still registered.
    # Without standing down here the line would simply be written back.
    return 0 if ( $self->{'action'} // '' ) eq 'uninstall';

    # A disabled vhost serves i-MSCP's own placeholder and a forward vhost
    # serves a redirect; neither is the customer's site, so neither is cached.
    return 0 if index( $data->{'VHOST_TYPE'}, 'disabled' ) != -1
        || index( $data->{'VHOST_TYPE'}, 'fwd' ) != -1;

    my $include = "    IncludeOptional \"$self->{'confDir'}/$data->{'DOMAIN_NAME'}.conf\"\n";

    ${ $cfgTpl } = replaceBloc(
        "# SECTION addons BEGIN.\n",
        "# SECTION addons END.\n",
        "    # SECTION addons BEGIN.\n"
            . getBloc( "# SECTION addons BEGIN.\n", "# SECTION addons END.\n", ${ $cfgTpl } )
            . $include
            . "    # SECTION addons END.\n",
        ${ $cfgTpl }
    );

    0;
}

=item _writeDomain( \%row )

 Generate and install the cache snippet for one domain

 Return int 0 on success, other on failure

=cut

sub _writeDomain
{
    my ($self, $row) = @_;

    my $domain = $row->{'domain_name'};
    my $cacheDir = "$self->{'cacheRoot'}/$domain";

    my $rs = eval {
        iMSCP::Dir->new( dirname => $cacheDir )->make( {
            mode  => 0750,
            user  => $self->{'httpd'}->{'config'}->{'HTTPD_USER'},
            group => $self->{'httpd'}->{'config'}->{'HTTPD_GROUP'}
        } );
        0;
    };
    if ( $@ || $rs ) {
        error( sprintf( "Couldn't create cache directory %s: %s", $cacheDir, $@ || 'unknown error' ) );
        return 1;
    }

    $rs = eval {
        iMSCP::Dir->new( dirname => $self->{'confDir'} )->make( { mode => 0755 } );
        0;
    };
    if ( $@ || $rs ) {
        error( sprintf( "Couldn't create %s: %s", $self->{'confDir'}, $@ || 'unknown error' ) );
        return 1;
    }

    my $file = iMSCP::File->new( filename => "$self->{'confDir'}/$domain.conf" );
    $file->set( $self->_buildConf( $row, $cacheDir ) );
    $rs = $file->save();
    $rs ||= $file->mode( 0644 );
    $rs;
}

=item _buildConf( \%row, $cacheDir )

 Build the Apache configuration for one domain

 Return string

=cut

sub _buildConf
{
    my ($self, $row, $cacheDir) = @_;

    my $conf = <<"EOF";
# Apache disk cache for $row->{'domain_name'}
#
# Generated by the i-MSCP SGW_ApacheCache plugin. Any edit made here is lost
# the next time the domain's cache settings are saved.

CacheRoot            $cacheDir
# Keep the cache key anchored to the host instead of letting a front-controller
# rewrite such as /index.php collapse different pages onto the same key.
CacheKeyBaseURL      "http://$row->{'domain_name'}/"
CacheEnable          disk /
# The cache has to run as a normal handler rather than in the quick handler,
# otherwise the bypass rules below are evaluated too late to be seen.
CacheQuickHandler    Off
CacheDefaultExpire   $row->{'default_expire'}
CacheMaxExpire       $row->{'max_expire'}
CacheMaxFileSize     $row->{'max_file_size'}
# Never let a session cookie be written into a shared cache entry.
CacheIgnoreHeaders   Set-Cookie
CacheLock            On
EOF

    if ( $row->{'ignore_no_lastmod'} ) {
        $conf .= <<'EOF';
# WordPress sends HTML with no Last-Modified, no ETag and no freshness
# information at all, so without this nothing would ever be stored.
CacheIgnoreNoLastMod On
EOF
    }

    if ( $row->{'debug_headers'} ) {
        $conf .= "CacheHeader          On\n";
    }

    # ACME challenges must always reach the origin.
    my @paths = ( '/.well-known/', $self->_splitList( $row->{'bypass_paths'} ) );
    my @cookies = $self->_splitList( $row->{'bypass_cookies'} );

    if ( $row->{'wordpress_mode'} ) {
        push @paths, qw( /wp-admin /wp-login.php /wp-cron.php /wp-json /xmlrpc.php );
        # WordPress does not send Vary: Cookie, so nothing but these rules
        # keeps a signed-in visitor's page out of the shared cache.
        push @cookies, qw(
            wordpress_logged_in_ wp-postpass_ comment_author_
            woocommerce_items_in_cart wp_woocommerce_session_ edd_items_in_cart
        );
    }

    $conf .= "\n# --- Requests that must never be served from, or stored in, the cache ---\n";

    if ( @paths ) {
        # Matched against THE_REQUEST, the original request line, and not
        # against REQUEST_URI or CacheDisable.
        #
        # A pretty permalink such as /my-account is turned into /index.php by
        # WordPress's .htaccess, and that rewrite is an internal redirect: the
        # request is reprocessed from scratch, so anything keyed on the URL
        # sees /index.php and the environment set on the first pass has been
        # renamed out of the way with a REDIRECT_ prefix. Only paths that are
        # real files or directories, such as /wp-admin, would still match.
        # THE_REQUEST survives the redirect intact.
        #
        # The optional scheme and authority cover the absolute-URI form of the
        # request line, which a proxy may send.
        my $pattern = join '|', map { quotemeta } @{ $self->_unique( \@paths ) };
        $conf .= "SetEnvIfExpr \"%{THE_REQUEST} =~ m#^[A-Z]+ (?:[a-z]+://[^/]+)?($pattern)#\" imscp_nocache\n";
    }

    if ( @cookies ) {
        my $pattern = join '|', map { quotemeta } @{ $self->_unique( \@cookies ) };
        # Deliberately mod_setenvif and not an ap_expr on %{HTTP_COOKIE}:
        # referencing an HTTP_* variable in an expression makes Apache append
        # that header to the response's Vary, which would key every cache entry
        # on the visitor's entire cookie jar and destroy the hit rate.
        $conf .= "SetEnvIfNoCase Cookie \"$pattern\" imscp_nocache\n";
    }

    if ( $row->{'wordpress_mode'} ) {
        $conf .= <<'EOF';
SetEnvIfExpr "%{QUERY_STRING} =~ /(^|&)(s|preview|nocache)=/" imscp_nocache
EOF
    }

    $conf .= <<'EOF';
SetEnvIfExpr "%{REQUEST_METHOD} !~ /^(GET|HEAD)$/" imscp_nocache

# Two directives, two different jobs: the environment variable stops the
# response being stored, and the request-side Cache-Control stops a stored
# response being served back. Only the pair keeps a signed-in visitor both out
# of the cache and off a stale anonymous page.
SetEnvIf imscp_nocache . no-cache
RequestHeader set Cache-Control "no-cache" env=imscp_nocache
EOF

    if ( $row->{'debug_headers'} ) {
        $conf .= "Header always set X-Imscp-Bypass \"yes\" env=imscp_nocache\n";
    }

    my @deny = $self->_splitList( $row->{'deny_paths'} );

    if ( @deny ) {
        # Matched against the URL path with no anchor, so an entry such as
        # xmlrpc.php also covers /xmlrpc.php and //xmlrpc.php, which is how the
        # endpoint is usually probed.
        #
        # <LocationMatch> rather than a bypass rule or mod_rewrite: Location
        # sections merge last, so this wins over whatever the vhost's
        # <Directory> block and the customer's .htaccess grant, and access
        # control runs before both the cache and the per-directory rewrite that
        # turns a pretty permalink into /index.php.
        my $pattern = join '|', map { quotemeta } @{ $self->_unique( \@deny ) };
        $conf .= <<"EOF";

# --- Requests refused outright ---
<LocationMatch "(?:$pattern)">
    Require all denied
</LocationMatch>
EOF
    }

    if ( $row->{'static_expires'} ) {
        $conf .= <<'EOF';

# Static assets carry validators but no lifetime, so give them one; this is
# what lets a browser stop re-requesting them at all.
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/*                "access plus 30 days"
    ExpiresByType font/*                 "access plus 30 days"
    ExpiresByType text/css               "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    ExpiresByType text/javascript        "access plus 7 days"
</IfModule>
EOF
    }

    $conf;
}

=item _removeDomain( $domain )

 Remove the snippet and the cache directory for one domain

 Return int 0 on success, other on failure

=cut

sub _removeDomain
{
    my ($self, $domain) = @_;

    my $file = iMSCP::File->new( filename => "$self->{'confDir'}/$domain.conf" );
    if ( -f $file->{'filename'} ) {
        my $rs = $file->delFile();
        return $rs if $rs;
    }

    $self->_purgeDomain( $domain, 'remove' );
}

=item _purgeDomain( $domain [, $remove = false ] )

 Empty a domain's cache directory, or remove it entirely

 Return int 0 on success, other on failure

=cut

sub _purgeDomain
{
    my ($self, $domain, $remove) = @_;

    my $cacheDir = "$self->{'cacheRoot'}/$domain";
    return 0 unless -d $cacheDir;

    eval { iMSCP::Dir->new( dirname => $cacheDir )->remove(); };
    if ( $@ ) {
        error( sprintf( "Couldn't purge %s: %s", $cacheDir, $@ ) );
        return 1;
    }

    unless ( $remove ) {
        eval {
            iMSCP::Dir->new( dirname => $cacheDir )->make( {
                mode  => 0750,
                user  => $self->{'httpd'}->{'config'}->{'HTTPD_USER'},
                group => $self->{'httpd'}->{'config'}->{'HTTPD_GROUP'}
            } );
        };
        if ( $@ ) {
            error( sprintf( "Couldn't recreate %s: %s", $cacheDir, $@ ) );
            return 1;
        }
    }

    0;
}

=item _configuredDomains( )

 Return arrayref of domain names that currently have a generated snippet

=cut

sub _configuredDomains
{
    my ($self) = @_;

    return [] unless -d $self->{'confDir'};

    my @domains;
    eval {
        @domains = map { s/\.conf$//r }
            grep { /\.conf$/ }
            iMSCP::Dir->new( dirname => $self->{'confDir'} )->getFiles();
    };
    error( $@ ) if $@;

    \@domains;
}

=item _splitList( $text )

 Split a newline or comma separated user supplied list into trimmed entries

 Return list

=cut

sub _splitList
{
    my ($self, $text) = @_;

    return () unless defined $text && $text ne '';

    grep { length } map { s/^\s+|\s+$//gr } split /[\r\n,]+/, $text;
}

=item _unique( \@list )

 Return arrayref with duplicates removed, order preserved

=cut

sub _unique
{
    my ($self, $list) = @_;

    my %seen;
    [ grep { !$seen{$_}++ } @{ $list } ];
}

=item _enableApacheModules( )

 Enable the Apache modules the generated configuration depends on

 Return int 0 on success, other on failure

=cut

sub _enableApacheModules
{
    my ($self) = @_;

    my $rs = execute(
        'a2enmod cache cache_disk expires headers setenvif',
        \ my $stdout, \ my $stderr
    );
    debug( $stdout ) if $stdout;
    error( $stderr ) if $rs && $stderr;
    return $rs if $rs;

    eval {
        iMSCP::Dir->new( dirname => $self->{'cacheRoot'} )->make( {
            mode  => 0750,
            user  => $self->{'httpd'}->{'config'}->{'HTTPD_USER'},
            group => $self->{'httpd'}->{'config'}->{'HTTPD_GROUP'}
        } );
    };
    if ( $@ ) {
        error( sprintf( "Couldn't create %s: %s", $self->{'cacheRoot'}, $@ ) );
        return 1;
    }

    0;
}

=item _installCleaner( )

 Install a systemd timer that trims each domain's cache back to the size limit.

 Debian's own apache-htcacheclean unit takes a single -p path, which cannot
 express one cache root per domain, so the plugin ships its own.

 Return int 0 on success, other on failure

=cut

sub _installCleaner
{
    my ($self) = @_;

    my $script = iMSCP::File->new( filename => '/usr/local/sbin/imscp-htcacheclean' );
    $script->set( <<"EOF" );
#!/bin/sh
# Trim every per-domain Apache cache maintained by the i-MSCP SGW_ApacheCache
# plugin. Installed by the plugin; edits are lost on update.
set -e
for dir in $self->{'cacheRoot'}/*/; do
    [ -d "\$dir" ] || continue
    /usr/bin/htcacheclean -n -p "\$dir" -l $self->{'sizeLimit'} || true
done
EOF
    my $rs = $script->save();
    $rs ||= $script->mode( 0755 );
    return $rs if $rs;

    my $service = iMSCP::File->new( filename => '/etc/systemd/system/imscp-htcacheclean.service' );
    $service->set( <<'EOF' );
[Unit]
Description=Trim the per-domain Apache disk caches (i-MSCP SGW_ApacheCache)
Documentation=https://httpd.apache.org/docs/2.4/programs/htcacheclean.html

[Service]
Type=oneshot
User=root
ExecStart=/usr/local/sbin/imscp-htcacheclean
EOF
    $rs = $service->save();
    $rs ||= $service->mode( 0644 );
    return $rs if $rs;

    my $timer = iMSCP::File->new( filename => '/etc/systemd/system/imscp-htcacheclean.timer' );
    $timer->set( <<"EOF" );
[Unit]
Description=Periodically trim the per-domain Apache disk caches

[Timer]
OnBootSec=$self->{'cleanInterval'}
OnUnitActiveSec=$self->{'cleanInterval'}

[Install]
WantedBy=timers.target
EOF
    $rs = $timer->save();
    $rs ||= $timer->mode( 0644 );
    return $rs if $rs;

    # systemctl directly rather than iMSCP::Service: the latter resolves unit
    # names against i-MSCP's own service map and cannot see a .timer unit.
    $rs = execute(
        'systemctl daemon-reload && systemctl enable --now imscp-htcacheclean.timer',
        \ my $stdout, \ my $stderr
    );
    debug( $stdout ) if $stdout;
    if ( $rs ) {
        error( sprintf( "Couldn't enable imscp-htcacheclean.timer: %s", $stderr || 'unknown error' ) );
        return $rs;
    }

    0;
}

=item _removeCleaner( )

 Remove the htcacheclean timer

 Return int 0 on success, other on failure

=cut

sub _removeCleaner
{
    my ($self) = @_;

    # Ignore failures: the timer may already be gone.
    execute( 'systemctl disable --now imscp-htcacheclean.timer', \ my $out, \ my $err );
    debug( $err ) if $err;

    for my $path (
        '/etc/systemd/system/imscp-htcacheclean.timer',
        '/etc/systemd/system/imscp-htcacheclean.service',
        '/usr/local/sbin/imscp-htcacheclean'
    ) {
        next unless -f $path;
        my $rs = iMSCP::File->new( filename => $path )->delFile();
        return $rs if $rs;
    }

    execute( 'systemctl daemon-reload', \ my $stdout, \ my $stderr );

    0;
}

=item _rebuildAllVhosts( )

 Mark every vhost for rebuild, so the IncludeOptional line is added or removed

 Return int 0 on success, other on failure

=cut

sub _rebuildAllVhosts
{
    my ($self) = @_;

    my @statements = (
        "UPDATE domain SET domain_status = 'tochange' WHERE domain_status = 'ok'",
        "UPDATE subdomain SET subdomain_status = 'tochange' WHERE subdomain_status = 'ok'",
        "UPDATE domain_aliasses SET alias_status = 'tochange' WHERE alias_status = 'ok'",
        "UPDATE subdomain_alias SET subdomain_alias_status = 'tochange' WHERE subdomain_alias_status = 'ok'"
    );

    for my $sql ( @statements ) {
        my $rs = $self->{'db'}->doQuery( 'u', $sql );
        unless ( ref $rs eq 'HASH' ) {
            error( $rs );
            return 1;
        }
    }

    0;
}

=item _reloadHttpd( )

 Reload Apache, refusing to do so if the generated configuration is not valid

 Return int 0 on success, other on failure

=cut

sub _reloadHttpd
{
    my ($self) = @_;

    my $rs = execute( 'apache2ctl configtest', \ my $stdout, \ my $stderr );
    if ( $rs ) {
        error( sprintf( 'Generated Apache configuration is not valid: %s', $stderr || $stdout ) );
        return $rs;
    }

    eval { iMSCP::Service->getInstance()->reload( 'apache2' ); };
    if ( $@ ) {
        error( sprintf( "Couldn't reload Apache: %s", $@ ) );
        return 1;
    }

    0;
}

=item _checkRequirements( )

 Check that the Apache modules this plugin needs are available

 Return int 0 if all requirements are met, other otherwise

=cut

sub _checkRequirements
{
    my ($self) = @_;

    my $ret = 0;

    for my $module ( qw/ mod_cache.so mod_cache_disk.so mod_expires.so mod_headers.so / ) {
        next if -f "/usr/lib/apache2/modules/$module";
        error( sprintf( 'The Apache module %s is not available on this system', $module ) );
        $ret ||= 1;
    }

    unless ( -x '/usr/bin/htcacheclean' ) {
        error( 'htcacheclean was not found; install the apache2-utils package' );
        $ret ||= 1;
    }

    $ret;
}

=back

=head1 AUTHORS

 Cambell Prince <cambell.prince@gmail.com>

=cut

1;
__END__
