<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Schedule::command('messages:cleanup')->weekly()
//     ->withoutOverlapping()
//     ->onSuccess(function () {
//         \Log::info('Message cleanup completed successfully');
//     })->onFailure(function () {
//         \Log::error('Message cleanup failed');
//     });

Schedule::command('messages:download --memory-limit=256M')
    ->everyThirtyMinutes()
    ->withoutOverlapping(1800) // 30-minute overlap protection
    ->runInBackground()
    // ->sendOutputTo(storage_path('logs/message_downloads.log'))
    // ->emailOutputOnFailure(['admin@example.com']) // Optional: email on failure
    ->onSuccess(function () {
        \Log::info('Message download completed successfully');
    })
    ->onFailure(function () {
        \Log::error('Message download failed');
    });

// Schedule::command('device:whitelist {ip}')
//     ->everyMinute()
//     ->withoutOverlapping();

Schedule::command('files:move-dat --copy --batch-size=100')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path("logs/data_from_DATfile_logs"))
    ->onSuccess(function () {
        \Log::info('Scheduled message processing completed successfully');
        \Log::info('files:move-dat START at ' . now());
        \Log::info('files:move-dat END at ' . now());
    })
    ->onFailure(function () {
        \Log::error('Scheduled message processing failed');
    });



Schedule::command('files:parse-dat --output=csv --save')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->skip(function () {
        // Skip if move command is still running
        return Cache::has('files:move-dat:running');
    })
    ->onSuccess(function () {
        \Log::info('Scheduled message processing completed successfully');
        \Log::info('files:parse-dat START at ' . now());
        \Log::info('files:parse-dat END at ' . now());
    })
    ->onFailure(function () {
        \Log::error('Scheduled message processing failed');
    });
