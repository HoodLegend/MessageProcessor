<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Cache;

class BankScriptInitiatorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'messages:download
                           {--timeout=300 : Maximum execution time in seconds}
                           {--memory-limit=128M : Memory limit for the process}
                           {--force : Force execution even if another instance is running}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download .DAT files using receiptit-client.sh script with memory protection and overlap prevention';

    private const SCRIPT_PATH = '/var/www/fnb/nam/bin/receiptit-client.sh';
    private const MESSAGES_DIR = '/var/www/fnb/nam/ReceiptItClient/messages';
    private const WORKING_DIR = '/var/www/fnb/nam/bin';
    private const LOCK_KEY = 'messages_download_running';
    private const LOCK_TIMEOUT = 1800;
    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Set memory limit to prevent server crashes
        $this->setMemoryLimit();

        // Check if another instance is running
        if (!$this->acquireLock()) {
            return self::SUCCESS; // Exit quietly if another instance is running
        }

        try {
            return $this->executeDownload();
        } finally {
            // Always release the lock when done
            $this->releaseLock();
        }
    }

    /**
     * Set memory limit for this process
     */
    private function setMemoryLimit(): void
    {
        $memoryLimit = $this->option('memory-limit');

        if ($memoryLimit) {
            ini_set('memory_limit', $memoryLimit);
            $this->info("Memory limit set to: {$memoryLimit}");
        }

        // Monitor initial memory usage
        $initialMemory = $this->formatBytes(memory_get_usage(true));
        $peakMemory = $this->formatBytes(memory_get_peak_usage(true));

        if ($this->option('verbose')) {
            $this->line("Initial memory usage: {$initialMemory}");
            $this->line("Peak memory usage: {$peakMemory}");
        }
    }

    /**
     * Acquire execution lock to prevent overlapping instances
     */
    private function acquireLock(): bool
    {
        if ($this->option('force')) {
            $this->warn('Force option used - skipping lock check');
            Cache::put(self::LOCK_KEY, [
                'pid' => getmypid(),
                'started_at' => now()->toISOString(),
                'command' => $this->signature
            ], now()->addSeconds(self::LOCK_TIMEOUT));
            return true;
        }

        // Check if lock exists
        $existingLock = Cache::get(self::LOCK_KEY);

        if ($existingLock) {
            $startedAt = \Carbon\Carbon::parse($existingLock['started_at']);
            $runningFor = $startedAt->diffInMinutes(now());

            // Check if the process is actually still running
            $isProcessRunning = $this->isProcessRunning($existingLock['pid'] ?? null);

            if ($isProcessRunning && $runningFor < 30) {
                $this->warn("Another download process is already running (PID: {$existingLock['pid']})");
                $this->line("Started at: {$startedAt->format('Y-m-d H:i:s')}");
                $this->line("Running for: {$runningFor} minutes");
                return false;
            } else {
                // Stale lock or process not running - clean it up
                $this->warn("Cleaning up stale lock (process not running or timeout exceeded)");
                Cache::forget(self::LOCK_KEY);
            }
        }

        // Acquire new lock
        Cache::put(self::LOCK_KEY, [
            'pid' => getmypid(),
            'started_at' => now()->toISOString(),
            'command' => $this->signature
        ], now()->addSeconds(self::LOCK_TIMEOUT));

        return true;
    }

    /**
     * Release the execution lock
     */
    private function releaseLock(): void
    {
        Cache::forget(self::LOCK_KEY);
    }

    /**
     * Check if a process ID is still running
     */
    private function isProcessRunning(?int $pid): bool
    {
        if (!$pid) {
            return false;
        }

        // On Unix-like systems, check if process exists
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback method using ps command
        $process = new Process(['ps', '-p', (string)$pid]);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /**
     * Execute the main download process
     */
    private function executeDownload(): int
    {
        $this->info('Starting message download process...');
        $this->line('PID: ' . getmypid());
        $this->line('Script: ' . self::SCRIPT_PATH);
        $this->line('Messages Directory: ' . self::MESSAGES_DIR);
        $this->newLine();

        // Monitor system resources
        $this->monitorSystemResources();

        // Check if script exists and is executable
        if (!$this->validateScript()) {
            return self::FAILURE;
        }

        // Check/create messages directory
        if (!$this->ensureMessagesDirectory()) {
            return self::FAILURE;
        }

        // Count existing files before download
        $filesBefore = $this->countDatFiles();
        $this->info("Current .DAT files in messages directory: {$filesBefore}");

        // Execute the download script
        $result = $this->executeScript();

        // Monitor memory after execution
        $this->monitorMemoryUsage();

        if ($result['success']) {
            // Count files after download
            $filesAfter = $this->countDatFiles();
            $newFiles = $filesAfter - $filesBefore;

            $this->newLine();
            $this->info('Download completed successfully!');
            $this->info("Files before: {$filesBefore}");
            $this->info("Files after: {$filesAfter}");
            $this->info("New files downloaded: {$newFiles}");

            if ($newFiles > 0) {
                $this->displayNewFiles();
            } else {
                $this->warn('No new files were downloaded.');
            }

            return self::SUCCESS;
        } else {
            $this->error('Download failed!');
            $this->error('Error: ' . $result['error']);

            if (!empty($result['output'])) {
                $this->newLine();
                $this->error('Script output:');
                $this->line($result['output']);
            }

            return self::FAILURE;
        }
    }

    /**
     * Monitor system resources
     */
    private function monitorSystemResources(): void
    {
        // Check available disk space
        $freeSpace = disk_free_space(self::MESSAGES_DIR);
        $totalSpace = disk_total_space(self::MESSAGES_DIR);

        if ($freeSpace && $totalSpace) {
            $freeSpaceFormatted = $this->formatBytes($freeSpace);
            $usedPercentage = round((($totalSpace - $freeSpace) / $totalSpace) * 100, 2);

            $this->info("Disk space available: {$freeSpaceFormatted} ({$usedPercentage}% used)");

            // Warn if disk space is low
            if ($usedPercentage > 90) {
                $this->warn("⚠ Warning: Disk space is {$usedPercentage}% full!");
            }
        }

        // Check system load if available
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $this->info("System load: " . implode(', ', array_slice($load, 0, 3)));

            // Warn if system load is high
            if ($load[0] > 5) {
                $this->warn("⚠ Warning: High system load detected: {$load[0]}");
            }
        }
    }

    /**
     * Monitor memory usage throughout execution
     */
    private function monitorMemoryUsage(): void
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = ini_get('memory_limit');

        $this->info("Memory usage - Current: " . $this->formatBytes($currentMemory) .
                   " | Peak: " . $this->formatBytes($peakMemory) .
                   " | Limit: {$memoryLimit}");

        // Calculate memory usage percentage
        $limitBytes = $this->parseMemoryLimit($memoryLimit);
        if ($limitBytes > 0) {
            $memoryPercentage = ($peakMemory / $limitBytes) * 100;

            if ($memoryPercentage > 80) {
                $this->warn("Warning: Memory usage is at {$memoryPercentage}% of limit");
            }
        }

        // Force garbage collection to free memory
        gc_collect_cycles();
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0; // No limit
        }

        $limit = trim($limit);
        $lastChar = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($lastChar) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Validate that the script exists and is executable
     */
    private function validateScript(): bool
    {
        if (!file_exists(self::SCRIPT_PATH)) {
            $this->error("Script not found: " . self::SCRIPT_PATH);
            return false;
        }

        if (!is_executable(self::SCRIPT_PATH)) {
            $this->error("Script is not executable: " . self::SCRIPT_PATH);
            $this->line("Try running: chmod +x " . self::SCRIPT_PATH);
            return false;
        }

        $this->info("✓ Script found and executable");
        return true;
    }

    /**
     * Ensure messages directory exists
     */
    private function ensureMessagesDirectory(): bool
    {
        if (!is_dir(self::MESSAGES_DIR)) {
            $this->warn("Messages directory doesn't exist, attempting to create: " . self::MESSAGES_DIR);

            if (!mkdir(self::MESSAGES_DIR, 0755, true)) {
                $this->error("Failed to create messages directory: " . self::MESSAGES_DIR);
                return false;
            }

            $this->info("Created messages directory");
        } else {
            $this->info("Messages directory exists");
        }

        if (!is_writable(self::MESSAGES_DIR)) {
            $this->error("Messages directory is not writable: " . self::MESSAGES_DIR);
            return false;
        }

        return true;
    }

    /**
     * Execute the receiptit-client script with memory monitoring
     */
    private function executeScript(): array
    {
        $timeout = (int) $this->option('timeout');
        $verbose = $this->option('verbose');

        try {
            // Create the process with resource limits
            $process = new Process(
                ['./receiptit-client.sh', '-m'],
                self::WORKING_DIR,
                array_merge($_ENV, [
                    'MALLOC_ARENA_MAX' => '2', // Limit memory arenas
                    'MALLOC_MMAP_THRESHOLD_' => '131072', // Use mmap for large allocations
                ]),
                null,
                $timeout
            );

            $this->info("Executing: ./receiptit-client.sh -m");
            $this->info("Working directory: " . self::WORKING_DIR);
            $this->info("Timeout: {$timeout} seconds");
            $this->newLine();

            if ($verbose) {
                $this->line("Running script with verbose output...");
            }

            // Start the process
            $process->start();

            // Show progress indicator with memory monitoring
            $this->output->write('Downloading');
            $lastMemoryCheck = time();

            while ($process->isRunning()) {
                $this->output->write('.');

                // Check memory every 10 seconds
                if (time() - $lastMemoryCheck >= 10) {
                    $currentMemory = memory_get_usage(true);
                    $memoryMB = round($currentMemory / 1024 / 1024, 2);

                    if ($verbose) {
                        $this->newLine();
                        $this->line("  Memory: {$memoryMB}MB");
                        $this->output->write('Downloading');
                    }

                    $lastMemoryCheck = time();
                }

                sleep(1);
            }

            $this->newLine(2);

            // Get the result
            $exitCode = $process->getExitCode();
            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            if ($verbose || $exitCode !== 0) {
                if (!empty($output)) {
                    $this->line("Standard Output:");
                    $this->line($output);
                }

                if (!empty($errorOutput)) {
                    $this->line("Error Output:");
                    $this->line($errorOutput);
                }
            }

            if ($exitCode === 0) {
                return [
                    'success' => true,
                    'output' => $output,
                    'error_output' => $errorOutput
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "Script exited with code: {$exitCode}",
                    'output' => $output . "\n" . $errorOutput
                ];
            }

        } catch (ProcessFailedException $e) {
            return [
                'success' => false,
                'error' => 'Process failed: ' . $e->getMessage(),
                'output' => ''
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Unexpected error: ' . $e->getMessage(),
                'output' => ''
            ];
        }
    }

    /**
     * Count .DAT files in messages directory
     */
    private function countDatFiles(): int
    {
        if (!is_dir(self::MESSAGES_DIR)) {
            return 0;
        }

        $files = glob(self::MESSAGES_DIR . '/*.DAT');
        return count($files ?: []);
    }

    /**
     * Display information about new files
     */
    private function displayNewFiles(): void
    {
        $datFiles = glob(self::MESSAGES_DIR . '/*.DAT');

        if (empty($datFiles)) {
            return;
        }

        // Sort by modification time (newest first)
        usort($datFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $this->newLine();
        $this->info('Recent .DAT files:');

        $count = 0;
        foreach ($datFiles as $file) {
            if ($count >= 10) { // Show only last 10 files
                $remaining = count($datFiles) - $count;
                $this->line("  ... and {$remaining} more files");
                break;
            }

            $filename = basename($file);
            $size = $this->formatBytes(filesize($file));
            $modified = date('Y-m-d H:i:s', filemtime($file));

            $this->line("  - {$filename} ({$size}) - {$modified}");
            $count++;
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $size): string
    {
        if ($size == 0) {
            return '0 B';
        }

        $base = log($size, 1024);
        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];

        return round(pow(1024, $base - floor($base)), 2) . ' ' . $suffixes[floor($base)];
    }
}
