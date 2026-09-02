#!/bin/sh
# Install a WordPress site into the wpcache.test fixture domain, for exercising
# the plugin's cache rules against a real WordPress.
#
# Run inside the Vagrant box: sudo tools/setup-test-site.sh

set -e

DOMAIN=wpcache.test
DOCROOT=/var/www/virtual/$DOMAIN/htdocs
DBNAME=wp_wpcache
DBUSER=wp_wpcache
DBPASS=wpcache
# i-MSCP serves customer domains with PHP 7.3 on this box, so WordPress is
# pinned to the last branch that supports it. The cache-relevant response
# headers (no Cache-Control/Last-Modified on HTML, wordpress_logged_in_*
# cookies) are unchanged in later releases.
WPVERSION=6.4.5
WPUSER=wpadmin
WPPASS='Passw0rd!123'
WPEMAIL=cambell.prince@gmail.com

[ "$(id -u)" -eq 0 ] || { echo "$0: must be run as root" >&2; exit 1; }

WEBUSER=$(stat -c %U "$DOCROOT")

# Resolve the fixture domains locally so the installer and the test harness can
# reach them over the loopback interface.
for d in $DOMAIN blog.$DOMAIN wpalias.test shop.wpalias.test; do
    grep -q "[[:space:]]$d\$" /etc/hosts || echo "127.0.0.1 $d" >> /etc/hosts
done

mysql -e "CREATE DATABASE IF NOT EXISTS \`$DBNAME\`;"
mysql -e "CREATE USER IF NOT EXISTS '$DBUSER'@'localhost' IDENTIFIED BY '$DBPASS';"
mysql -e "GRANT ALL ON \`$DBNAME\`.* TO '$DBUSER'@'localhost'; FLUSH PRIVILEGES;"

if [ ! -x /usr/local/bin/wp ]; then
    curl -sSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    chmod +x /usr/local/bin/wp
fi

wp() {
    command sudo -u "$WEBUSER" WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
        -- /usr/local/bin/wp --path="$DOCROOT" "$@"
}

if [ "$(wp core version 2>/dev/null)" != "$WPVERSION" ]; then
    rm -f "$DOCROOT/index.html"
    wp core download --version="$WPVERSION" --force
fi

[ -f "$DOCROOT/wp-config.php" ] || \
    wp config create --dbname="$DBNAME" --dbuser="$DBUSER" --dbpass="$DBPASS"

wp core is-installed 2>/dev/null || wp core install \
    --url="http://$DOMAIN/" --title="WP Cache Test" \
    --admin_user="$WPUSER" --admin_password="$WPPASS" --admin_email="$WPEMAIL" \
    --skip-email

# Pretty permalinks, so cached URLs look like a real site's
wp rewrite structure '/%postname%/'

# wp-cli declines to write .htaccess unless told which Apache modules are
# loaded, so write WordPress's standard block directly. The vhost sets
# AllowOverride All, so pretty permalinks resolve without a vhost change.
cat > "$DOCROOT/.htaccess" <<'HTACCESS'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS
chown "$WEBUSER:$WEBUSER" "$DOCROOT/.htaccess"
wp post list --post_type=post --name=second-post --format=count | grep -qv '^0$' || \
    wp post create --post_title='Second post' --post_status=publish --porcelain >/dev/null

echo
echo "WordPress ready at http://$DOMAIN/  (admin: $WPUSER / $WPPASS)"
