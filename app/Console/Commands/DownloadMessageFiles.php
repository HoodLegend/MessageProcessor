<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Log;

class DownloadMessageFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bank:download-files';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download bank files from server using shell script';

    /**
     * Execute the console command.
     */
    public function handle()
    {
                try {
            $this->info('Starting bank .DAT file download...');

            // Path to your shell script on the server
            $scriptPath = '/var/www/fnb/nam/bin/receiptit-client.sh';

            // Check if script exists
            if (!file_exists($scriptPath)) {
                $this->error("Script not found at: {$scriptPath}");
                Log::error("Bank download script not found: {$scriptPath}");
                return 1;
            }

            // Make sure the script is executable
            chmod($scriptPath, 0755);

            // Execute the shell script
            $output = [];
            $returnCode = 0;

            exec("bash {$scriptPath} 2>&1", $output, $returnCode);

            if ($returnCode === 0) {
                $this->info('Bank file download completed successfully');
                Log::info('Bank .DAT files downloaded successfully', [
                    'output' => implode("\n", $output)
                ]);
            } else {
                $this->error('Bank file download failed');
                Log::error('Bank file download failed', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output)
                ]);
                return 1;
            }

        } catch (\Exception $e) {
            $this->error('Error executing bank download: ' . $e->getMessage());
            Log::error('Bank download exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;

    }
}
