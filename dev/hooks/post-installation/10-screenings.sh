#!/bin/sh
# Runs once, right after Nextcloud's first installation inside the container.
set -e

php /var/www/html/occ background:cron
php /var/www/html/occ config:system:set debug --value=true --type=boolean

# Send all mail to Mailpit.
php /var/www/html/occ config:system:set mail_smtpmode --value=smtp
php /var/www/html/occ config:system:set mail_smtphost --value=mailpit
php /var/www/html/occ config:system:set mail_smtpport --value=1025 --type=integer
php /var/www/html/occ config:system:set mail_from_address --value=screenings
php /var/www/html/occ config:system:set mail_domain --value=localhost

php /var/www/html/occ app:disable firstrunwizard || true
php /var/www/html/occ app:enable screenings
