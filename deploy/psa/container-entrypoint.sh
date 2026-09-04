#!/bin/sh
set -eu

install -d -m 0750 /var/lib/itflow/uploads

if [ ! -e /var/lib/itflow/uploads/.htaccess ] && [ -e /usr/local/share/itflow-uploads/.htaccess ]; then
    cp -a /usr/local/share/itflow-uploads/. /var/lib/itflow/uploads/
fi

if [ "$(id -u)" -eq 0 ]; then
    chown -R www-data:www-data /var/lib/itflow
fi

exec "$@"
