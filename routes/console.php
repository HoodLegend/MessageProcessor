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
