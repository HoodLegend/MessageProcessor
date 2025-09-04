<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Inertia\Inertia;

class MessageController extends Controller
{
    /**
     * Display a list of all CSV files and their data
     */
    public function index()
    {
        $availableDates = $this->getAvailableDates();
        $totalRecords = $this->getTotalRecords();

        return Inertia::render('Messages', [
            'availableDates' => $availableDates,
            'initialDate' => $availableDates->first()['value'] ?? '',
            'totalRecords' => $totalRecords
        ]);
    }

    /**
     * Server-side processing endpoint for DataTables
     */
    public function getData(Request $request)
    {
        try {
            $draw = $request->input('draw', 1);
            $start = $request->input('start', 0);
            $length = $request->input('length', 25);
            $dateFilter = (string) $request->input('date_filter', '');

            // Use custom method that streams only a page of data
            $data = $this->getTransactionPage($dateFilter, $start, $length);
            $totalRecords = $this->getCachedTransactionCount($dateFilter);
            return response()->json([
                'draw' => intval($draw),
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalRecords,
                'data' => $data->toArray()
            ]);

        } catch (\Exception $e) {
            \Log::error('Transaction data error: ' . $e->getMessage());

            return response()->json([
                'draw' => intval($request->input('draw', 1)),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Failed to load transaction data'
            ]);
        }
    }

    private function getTransactionPage(string $dateFilter, int $start, int $length): Collection
    {
        $rows = collect();
        $exportDirectory = 'exports';
        $files = Storage::files($exportDirectory);
        $rowCount = 0;

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'csv')
                continue;

            $filename = basename($file, '.csv');
            if (preg_match('/(\d{8})/', $filename, $matches)) {
                $fileDate = $matches[1];
                if (!$this->shouldIncludeFile($fileDate, $dateFilter))
                    continue;

                $stream = Storage::readStream($file);
                if (!$stream)
                    continue;

                $headerSkipped = false;

                while (($line = fgets($stream)) !== false) {
                    if (!$headerSkipped) {
                        $headerSkipped = true;
                        continue;
                    }

                    if (trim($line) === '')
                        continue;

                    if ($rowCount < $start) {
                        $rowCount++;
                        continue;
                    }

                    if ($rows->count() >= $length)
                        break;

                    $columns = str_getcsv($line);

                    if (count($columns) >= 5) {
                        $rows->push([
                            'transaction_date' => $columns[0] ?? '',
                            'transaction_time' => $columns[1] ?? '',
                            'amount' => $columns[2] ?? '',
                            'mobile_number' => $columns[3] ?? '',
                            'transaction_id' => $columns[4] ?? ''
                        ]);
                    }

                    $rowCount++;
                }

                fclose($stream);

                if ($rows->count() >= $length)
                    break;
            }
        }

        return $rows;
    }

    private function getCachedTransactionCount(string $dateFilter): int
    {
        $cacheKey = 'transaction_count_' . ($dateFilter ?: 'all');
        $datesCacheKey = 'available_dates_' . ($dateFilter ?: 'all');

        // Use cache() helper instead of Cache facade to avoid resolution issues
        cache()->remember($datesCacheKey, now()->addMinutes(10), function () use ($dateFilter) {
            return $this->getAvailableDates($dateFilter);
        });

        return cache()->remember($cacheKey, now()->addMinutes(10), function () use ($dateFilter) {
            $count = 0;
            $exportDirectory = 'exports';
            $files = Storage::files($exportDirectory);

            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) !== 'csv') {
                    continue;
                }

                $filename = basename($file, '.csv');
                if (!preg_match('/(\d{8})/', $filename, $matches)) {
                    continue;
                }

                $fileDate = $matches[1];
                if (!$this->shouldIncludeFile($fileDate, $dateFilter)) {
                    continue;
                }

                $stream = Storage::readStream($file);
                if (!$stream) {
                    continue;
                }

                $headerSkipped = false;

                while (($line = fgets($stream)) !== false) {
                    if (!$headerSkipped) {
                        $headerSkipped = true;
                        continue;
                    }

                    if (trim($line) !== '') {
                        $count++;
                    }
                }

                fclose($stream);
            }

            return $count;
        });
    }

    public function export(Request $request)
    {
        $dateFilter = (string) $request->input('date_filter', '');
        $format = $request->input('format', 'csv');

        $data = $this->getTransactionData($dateFilter);

        if ($format === 'csv') {
            return $this->exportToCsv($data, $dateFilter);
        }

        return response()->json($data);
    }

/**
     * Get available dates from CSV files
     */
    private function getAvailableDates(?string $dateFilter = ''): Collection
    {
        $exportDirectory = 'exports';
        $dates = collect();

        if (!Storage::exists($exportDirectory)) {
            return $dates;
        }

        $files = Storage::files($exportDirectory);

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'csv') {
                $filename = basename($file, '.csv');

                if (preg_match('/(\d{8})/', $filename, $matches)) {
                    $dateString = $matches[1];

                    // Apply date filter here
                    if (!$this->shouldIncludeFile($dateString, $dateFilter)) {
                        continue;
                    }

                    $recordCount = $this->getRecordCount($file);

                    $dates->push([
                        'value' => $dateString,
                        'label' => $this->formatDateLabel($dateString),
                        'count' => $recordCount,
                        'file' => $file
                    ]);
                }
            }
        }

        // Sort by date descending
        return $dates->sortByDesc('value')->values();
    }


    /**
     * Get transaction data based on date filter
     */
    private function getTransactionData(?string $dateFilter = ''): LazyCollection
    {
        $exportDirectory = 'exports';
        if (!Storage::exists($exportDirectory)) {
            return LazyCollection::empty();
        }

        $files = Storage::files($exportDirectory);

        return LazyCollection::make(function () use ($files, $dateFilter) {
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) !== 'csv') {
                    continue;
                }

                $filename = basename($file, '.csv');
                if (preg_match('/(\d{8})/', $filename, $matches)) {
                    $fileDateString = $matches[1];
                    if ($this->shouldIncludeFile($fileDateString, $dateFilter)) {
                        $fileData = $this->parseCSVFile($file);
                        foreach ($fileData as $row) {
                            yield $row;
                        }
                    }
                }
            }
        });
    }

    /**
     * Check if file should be included based on date filter
     */
    private function shouldIncludeFile(string $fileDateString, string $dateFilter): bool
    {
        if (empty($dateFilter)) {
            return true;
        }

        $fileDate = Carbon::createFromFormat('Ymd', $fileDateString);

        switch ($dateFilter) {
            case 'last7':
                return $fileDate->greaterThanOrEqualTo(Carbon::now()->subDays(7));
            case 'thisMonth':
                return $fileDate->month === Carbon::now()->month &&
                       $fileDate->year === Carbon::now()->year;
            default:
                return $fileDateString === $dateFilter;
        }
    }

    /**
     * Parse CSV file and return data collection
     */
    private function parseCSVFile(string $filePath): LazyCollection
    {
        return LazyCollection::make(function () use ($filePath) {
            $stream = Storage::readStream($filePath);

            if (!$stream) {
                \Log::error("Failed to open file: $filePath");
                return;
            }

            $headerSkipped = false;

            while (($line = fgets($stream)) !== false) {
                if (!$headerSkipped) {
                    $headerSkipped = true;
                    continue; // Skip the header
                }

                if (trim($line) === '')
                    continue;

                $columns = str_getcsv($line);

                if (count($columns) >= 5) {
                    yield [
                        'transaction_date' => $columns[0] ?? '',
                        'transaction_time' => $columns[1] ?? '',
                        'amount' => $columns[2] ?? '',
                        'mobile_number' => $columns[3] ?? '',
                        'transaction_id' => $columns[4] ?? ''
                    ];
                }
            }

            fclose($stream);
        });
    }

 /**
     * Get record count for a CSV file
     */
    private function getRecordCount(string $filePath): int
    {
        try {
            $content = Storage::get($filePath);
            $lines = explode("\n", trim($content));

            // Subtract 1 for header row
            return max(0, count($lines) - 1);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Format date string for display
     */
    private function formatDateLabel(string $dateString): string
    {
        try {
            $date = Carbon::createFromFormat('Ymd', $dateString);
            return $date->format('M j, Y');
        } catch (\Exception $e) {
            return $dateString;
        }
    }

    /**
     * Get total records across all files
     */
    private function getTotalRecords(): int
    {
        return $this->getTransactionData()->count();
    }

    /**
     * Export data to CSV
     */
    private function exportToCsv(LazyCollection $data, string $dateFilter)
    {
        // $filename = ($dateFilter ?: 'all') . '.csv';

        $filename = preg_replace('/\.csv$/', '', $dateFilter ?: 'all') . '.csv';

        $headers = [
            'Content-Type' => 'applciation/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($data) {
            $file = fopen('php://output', 'w');

            // Write header
            fputcsv($file, ['Transaction Date', 'Transaction Time', 'Amount', 'Mobile Number', 'Transaction ID']);

            // Write data
            foreach ($data as $row) {
                fputcsv($file, [
                    $row['transaction_date'],
                    $row['transaction_time'],
                    $row['amount'],
                    $row['mobile_number'],
                    $row['transaction_id']
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
