<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');



// Schedule::command('messages:download --memory-limit=256M')
//     ->everyThirtyMinutes()
//     ->withoutOverlapping(1800) // 30-minute overlap protection
//     ->runInBackground()
//     // ->sendOutputTo(storage_path('logs/message_downloads.log'))
//     // ->emailOutputOnFailure(['admin@example.com']) // Optional: email on failure
//     ->onSuccess(function () {
//         \Log::info('Message download completed successfully');
//     })
//     ->onFailure(function () {
//         \Log::error('Message download failed');
//     });

// Schedule::command('device:whitelist {ip}')
//     ->everyMinute()
//     ->withoutOverlapping();

// Schedule::command('files:move-dat --copy --batch-size=100')
//     ->everyFiveMinutes()
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->appendOutputTo(storage_path("logs/data_from_DATfile_logs"))
//     ->onSuccess(function () {
//         \Log::info('Scheduled message processing completed successfully');
//         \Log::info('files:move-dat START at ' . now());
//         \Log::info('files:move-dat END at ' . now());
//     })
//     ->onFailure(function () {
//         \Log::error('Scheduled message processing failed');
//     });



Schedule::command('files:parse-dat --output=csv --save')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path("logs/parse_dat_summary.log"))
    ->onSuccess(function () {
        \Log::info('Scheduled message processing completed successfully');
        \Log::info('files:parse-dat START at ' . now());
        \Log::info('files:parse-dat END at ' . now());
    })
    ->onFailure(function () {
        \Log::error('Scheduled message processing failed');
    });

// Schedule::command('files:send-to-accounting --batch-size=15 --max-runtime=90')
//     ->everyTwoMinutes()
//     ->withoutOverlapping(900) // 15-minute overlap protection for network issues
//     ->runInBackground()
//     ->appendOutputTo(storage_path("logs/send_accounting.log"))
//     ->onSuccess(function () {
//         \Log::info('send-to-accounting completed at ' . now());
//     })
//     ->onFailure(function () {
//         \Log::error('send-to-accounting failed at ' . now());
//     });
