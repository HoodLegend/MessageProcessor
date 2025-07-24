<?php

namespace App\Console\Commands;

use Cache;
use Illuminate\Console\Command;

class WhitelistDevice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device:whitelist {ip}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Whitelist an IP and clear its access cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
                $ip = $this->argument('ip');
        $configPath = resource_path('config/access-control.conf');

        // Append IP if not already in file
        if (!str_contains(file_get_contents($configPath), $ip)) {
            file_put_contents($configPath, "\n" . $ip, FILE_APPEND);
        }

        Cache::forget('device_access_' . md5($ip));

        $this->info("IP $ip whitelisted and cache cleared.");
    }
}
