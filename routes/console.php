<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('messages:cleanup')->weekly()
    ->withoutOverlapping()
    ->onSuccess(function () {
        \Log::info('Message cleanup completed successfully');
    })->onFailure(function () {
        \Log::error('Message cleanup failed');
    });

Schedule::command('device:whitelist {ip}')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('files:move-dat --copy')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path("logs/data_from_DATfile_logs"))
    ->onSuccess(function () {
        \Log::info('Scheduled message processing completed successfully');
    })
    ->onFailure(function () {
        \Log::error('Scheduled message processing failed');
    });



Schedule::command('files:parse-dat --output=csv --save')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path("logs/parsed_data_from_DatFile_logs"))
    ->onSuccess(function () {
        \Log::info('Scheduled message processing completed successfully');
    })
    ->onFailure(function () {
        \Log::error('Scheduled message processing failed');
    });


// Send data to server every 5 minutes (offset by 2 minutes to ensure data is processed first)
// Schedule::command('data:send-to-server')
//     ->everyFiveMinutes()
//     ->skip(function () {
//         // Skip if no data in Redis
//         $redis = \Illuminate\Support\Facades\Redis::connection();
//         return !$redis->exists('transaction-records') &&
//             !$redis->exists('local-file-data');
//     })
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->onSuccess(function () {
//         \Log::info('Data transmission completed successfully');
//     })
//     ->onFailure(function () {
//         \Log::error('Data transmission failed');
//     });
