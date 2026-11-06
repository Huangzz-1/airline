<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Database\Seeders\MarketplaceFlightSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

final class DemoFlights extends Command
{
    protected $signature = 'airline:demo-flights {--start= : First Vietnam local date, defaults to tomorrow} {--days=14 : Number of days, 1 to 31} {--preview : Only display planned flight count}';

    protected $description = 'Add simulated multi-airline schedules, fares and connecting flights without resetting existing data';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Demo schedules are only available in local/testing environments.');

            return self::FAILURE;
        }
        $input = Validator::make(['start' => $this->option('start') ?: now('Asia/Ho_Chi_Minh')->addDay()->toDateString(), 'days' => $this->option('days')], ['start' => 'required|date_format:Y-m-d|after_or_equal:today', 'days' => 'required|integer|between:1,31'])->validate();
        $start = Carbon::parse($input['start'], 'Asia/Ho_Chi_Minh');
        $seeder = new MarketplaceFlightSeeder;
        if ($this->option('preview')) {
            $this->info(count($seeder->plan($start, (int) $input['days'])).' planned simulated flights. No schedules were written.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('marketplace-demo-flights', 600);
        if (! $lock->get()) {
            $this->error('Another schedule import is running.');

            return self::FAILURE;
        }
        try {
            $report = $seeder->generate($start, (int) $input['days']);
            $path = storage_path('app/private/demo/multi-airline-flights.json');
            File::ensureDirectoryExists(dirname($path));
            File::replace($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info('Created '.$report['created'].' simulated flights; preserved '.$report['preserved'].' existing flights.');
            $this->line('Dates: '.$report['start'].' to '.$report['end'].' (Vietnam time).');
            $this->line('Report: '.$path);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
