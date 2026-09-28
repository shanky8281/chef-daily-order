<?php
/* Copy orders not yet in the Google Sheet. cPanel cron, every 30 minutes:
 *     php /home/CPANELUSER/public_html/buy.mirchi.cl/api/cron/sync-sheet.php */
declare(strict_types=1);
require __DIR__ . '/_cli.php';
$result = sync_to_sheet();
echo json_encode($result), PHP_EOL;
exit(isset($result['error']) ? 1 : 0);
