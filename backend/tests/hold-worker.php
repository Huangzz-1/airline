<?php

use App\Modules\Booking\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'airline_test') {
    exit(99);
}
while (microtime(true) < (float) $argv[2]) {
    usleep(1000);
}
try {
    app(BookingService::class)->hold((int) $argv[1], 1, 'race-key-'.$argv[1]);
    echo 'HELD';
} catch (HttpException $e) {
    echo $e->getStatusCode();
}
