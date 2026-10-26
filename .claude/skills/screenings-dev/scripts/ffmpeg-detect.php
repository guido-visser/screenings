<?php
// What Ffmpeg::detect() finds, optionally under a production-like open_basedir:
//   "$D" compose exec -T -u www-data nextcloud sh -c 'cat > /tmp/d.php' < .claude/skills/screenings-dev/scripts/ffmpeg-detect.php
//   "$D" compose exec -T -u www-data nextcloud sh -c 'cd /var/www/html && php -d open_basedir=/var/www/html:/tmp /tmp/d.php'
// Plesk limits the *web* PHP to the site folder (cron's CLI PHP has no limit).
require 'lib/base.php';
echo 'open_basedir: ', ini_get('open_basedir'), "\n";
echo 'is_executable(/usr/bin/ffmpeg): ', var_export(@is_executable('/usr/bin/ffmpeg'), true), "\n";
$ffmpeg = \OCP\Server::get(\OCA\Screenings\Media\Ffmpeg::class);
echo json_encode($ffmpeg->detect()), "\n";
