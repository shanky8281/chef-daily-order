<?php
/* Daily ODEPA reference prices. cPanel cron, every day at 06:30:
 *     php /home/CPANELUSER/buy.mirchi.cl/api/cron/market-prices.php
 * Optional: a CSV file or URL as the first argument instead of looking it up on ODEPA. */
declare(strict_types=1);
require __DIR__ . '/_cli.php';
try {
    $result = update_market_prices($argv[1] ?? null);
    echo "Updated {$result['updated']} market prices (market date {$result['marketDate']}).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Market prices not updated: ' . $e->getMessage() . "\n");
    exit(1);
}
