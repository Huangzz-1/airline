<?php

use App\Models\User;
use App\Modules\Booking\ReservationService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
foreach (['ops', 'mysql', 'finance'] as $node) {
    if (config("database.connections.$node.database") !== 'airline_test' || config("database.connections.$node.username") !== 'airline_test') {
        exit(99);
    }
}
while (microtime(true) < (float) $argv[3]) {
    usleep(1000);
}
try {
    $input = json_decode(base64_decode($argv[2], true), true, 32, JSON_THROW_ON_ERROR);
    $user = User::findOrFail((int) $argv[1]);
    $r = app(ReservationService::class)->hold($user, $input);
    echo json_encode(['status' => 201, 'id' => $r->id]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
