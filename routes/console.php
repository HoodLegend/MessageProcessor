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

Schedule::command('file:process-local', ['app/private/uploads/messages/2lOJrnCTdEBlMg1Iv85ANmCV9S5I3uPZcmmx4k0U.txt'])
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Log::info('Scheduled message processing completed successfully');
    })
    ->onFailure(function () {
        \Log::error('Scheduled message processing failed');
    });

// Send data to server every 5 minutes (offset by 2 minutes to ensure data is processed first)
Schedule::command('data:send-to-server')
    ->everyFiveMinutes()
    ->skip(function () {
        // Skip if no data in Redis
        $redis = \Illuminate\Support\Facades\Redis::connection();
        return !$redis->exists('transaction-records') &&
            !$redis->exists('local-file-data');
    })
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Log::info('Data transmission completed successfully');
    })
    ->onFailure(function () {
        \Log::error('Data transmission failed');
    });

    Schedule::command('receipts:process')
             ->everyFiveMinutes()
            ->withoutOverlapping() // Prevent multiple instances from running
            ->runInBackground()
            ->onSuccess(function () {
                \Log::info('Receipt processing completed successfully');
            })
            ->onFailure(function () {
                \Log::error('Receipt processing failed');
            });


 // Run download script every minute
    // Schedule::command('bank:download-files')
    //          ->everyMinute()
    //          ->withoutOverlapping()
    //          ->runInBackground()
    //          ->appendOutputTo(storage_path('logs/downloaded_messages.log'));

    // Decode messages every 2 minutes (offset to avoid conflicts)
    // Schedule::command('bank:decode-messages')
    //          ->everyTwoMinutes()
    //          ->withoutOverlapping()
    //          ->runInBackground()
    //          ->appendOutputTo(storage_path('logs/decoded_messages.log'));
