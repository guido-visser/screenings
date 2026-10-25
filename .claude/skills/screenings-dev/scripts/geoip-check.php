<?php
// Runs inside the Nextcloud container: php geoip-check.php
require '/var/www/html/lib/base.php';
\OC_App::loadApp('screenings');

$geo = \OCP\Server::get(\OCA\Screenings\Tracking\GeoIpService::class);
echo json_encode($geo->status(), JSON_PRETTY_PRINT), "\n";
// Addresses that MaxMind's test database contains, plus ones it must skip.
foreach (['81.2.69.142', '2.125.160.216', '89.160.20.112', '216.160.83.56', '2001:218::1', '8.8.8.8', '192.168.1.10', '172.18.0.1', '127.0.0.1', 'garbage'] as $ip) {
	printf("%-16s %s\n", $ip, json_encode($geo->lookup($ip)));
}
