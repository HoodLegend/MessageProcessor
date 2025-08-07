<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Generator;
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
            $search = $request->input('search.value', '');
            $orderColumn = $request->input('order.0.column', 1);
            $orderDir = $request->input('order.0.dir', 'desc');
            $dateFilter = $request->input('date_filter', '');

            // Get filtered data
            $allData = $this->getTransactionData($dateFilter);

            // Apply search filter
            if (!empty($search)) {
                $allData = $allData->filter(function ($item) use ($search) {
                    return stripos($item['transaction_id'], $search) !== false ||
                           stripos($item['mobile_number'], $search) !== false ||
                           stripos($item['amount'], $search) !== false ||
                           stripos($item['transaction_date'], $search) !== false;
                });
            }

            $totalRecords = $allData->count();
            $totalFiltered = $totalRecords;

            // Apply sorting
            $columns = ['transaction_id', 'transaction_date', 'transaction_time', 'amount', 'mobile_number'];
            if (isset($columns[$orderColumn])) {
                $sortField = $columns[$orderColumn];
                $allData = $allData->sortBy($sortField, SORT_REGULAR, $orderDir === 'desc');
            }

            // Apply pagination
            $data = $allData->skip($start)->take($length)->values();

            return response()->json([
                'draw' => intval($draw),
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalFiltered,
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

        /**
     * Export transactions data
     */
    public function export(Request $request)
    {
        $dateFilter = $request->input('date_filter', '');
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
    private function getAvailableDates(): Collection
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

                // Extract date from filename (assuming format: transactions_YYYYMMDD.csv)
                if (preg_match('/transactions_(\d{8})/', $filename, $matches)) {
                    $dateString = $matches[1];
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
    private function getTransactionData(?string $dateFilter = ''): Generator

    {
        $exportDirectory = 'exports';
        if (!Storage::exists($exportDirectory)) {
            return;
        }

        $files = Storage::files($exportDirectory);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'csv') {
                continue;
            }

            $filename = basename($file, '.csv');
            if (preg_match('/(\d{8})/', $filename, $matches)) {
                $fileDateString = $matches[1];
                if ($this->shouldIncludeFile($fileDateString, $dateFilter)) {
                    // Yield each row instead of collecting all data
                    $fileData = $this->parseCSVFile($file);
                    foreach ($fileData as $row) {
                        yield $row;
                    }
                }
            }
        }
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
    private function parseCSVFile(string $filePath): Collection
    {
        try {
            $content = Storage::get($filePath);
            $lines = explode("\n", trim($content));

            // Remove header line
            if (!empty($lines) && strpos($lines[0], 'Transaction Date') !== false) {
                array_shift($lines);
            }

            $data = collect();

            foreach ($lines as $line) {
                if (trim($line) === '') continue;

                $columns = str_getcsv($line);

                if (count($columns) >= 5) {
                    $data->push([
                        'transaction_date' => $columns[0] ?? '',
                        'transaction_time' => $columns[1] ?? '',
                        'amount' => $columns[2] ?? '',
                        'mobile_number' => $columns[3] ?? '',
                        'transaction_id' => $columns[4] ?? ''
                    ]);
                }
            }

            return $data;

        } catch (\Exception $e) {
            \Log::error("Error parsing CSV file {$filePath}: " . $e->getMessage());
            return collect();
        }
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
    private function exportToCsv(Collection $data, string $dateFilter)
    {
        $filename = ($dateFilter ?: 'all') . '_' . date('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
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



    // public function getCsvData(Request $request)
    // {
    //     try {
    //         $directory = 'exports';
    //         $files = Storage::files($directory);

    //         $csvFiles = array_filter($files, function ($file) {
    //             return pathinfo($file, PATHINFO_EXTENSION) === 'csv';
    //         });

    //         $data = [];
    //         $fileName = '';

    //         if (!empty($csvFiles)) {
    //             // Get the latest file
    //             usort($csvFiles, function ($a, $b) {
    //                 return Storage::lastModified($b) - Storage::lastModified($a);
    //             });

    //             $latestFile = $csvFiles[0];
    //             $fileName = basename($latestFile);
    //             $csvContent = Storage::get($latestFile);

    //             $lines = explode("\n", trim($csvContent));
    //             $header = str_getcsv(array_shift($lines));

    //             foreach ($lines as $line) {
    //                 if (trim($line)) {
    //                     $row = str_getcsv($line);
    //                     $data[] = [
    //                         'transaction_date' => $row[0] ?? '',
    //                         'transaction_time' => $row[1] ?? '',
    //                         'amount' => $row[2] ?? '',
    //                         'mobile_number' => $row[3] ?? '',
    //                         'transaction_id' => $row[4] ?? '',
    //                     ];
    //                 }
    //             }
    //         }

    //         return Inertia::render('Messages', [
    //             'data' => $data,
    //             'fileName' => $fileName,
    //             'totalRecords' => count($data),
    //             'currentFilePath' => $latestFile ?? null,
    //         ]);

    //     } catch (\Exception $e) {
    //         return Inertia::render('Messages', [
    //             'data' => [],
    //             'fileName' => '',
    //             'totalRecords' => 0,
    //             'error' => $e->getMessage(),
    //         ]);
    //     }
    // }

    // public function downloadCsv($filename)
    // {
    //     $filePath = 'exports/' . $filename;

    //     if (!Storage::exists($filePath)) {
    //         abort(404, 'File not found');
    //     }

    //     // Security check - only allow CSV files from exports directory
    //     if (pathinfo($filename, PATHINFO_EXTENSION) !== 'csv') {
    //         abort(403, 'Invalid file type');
    //     }

    //     return Storage::download($filePath, $filename);
    // }

    /**
     * Parse a CSV file and return collection of transactions
     */
    // private function parseCSVFile(string $fileName): Collection
    // {
    //     $content = Storage::get("csv_exports/{$fileName}");
    //     $lines = explode("\n", $content);

    //     // Remove header line and empty lines
    //     $dataLines = array_filter(array_slice($lines, 1), function ($line) {
    //         return !empty(trim($line));
    //     });

    //     $transactions = collect();

    //     foreach ($dataLines as $line) {
    //         $fields = str_getcsv($line);

    //         if (count($fields) >= 6) {
    //             $transactions->push([
    //                 'transaction_date' => $fields[0],
    //                 'transaction_time' => $fields[1],
    //                 'amount' => $fields[2],
    //                 'mobile_number' => $fields[3],
    //                 'transaction_id' => $fields[4]
    //             ]);
    //         }
    //     }

    //     return $transactions;
    // }

    /**
     * Filter transactions based on search term
     */
    // private function filterTransactions(Collection $transactions, string $search)
    // {
    //     return $transactions->filter(function ($transaction) use ($search) {
    //         return stripos($transaction['mobile_number'], $search) !== false ||
    //             stripos($transaction['transaction_id'], $search) !== false ||
    //             stripos($transaction['amount'], $search) !== false ||
    //             stripos($transaction['date'], $search) !== false;
    //     });
    // }

    /**
     * Filter transactions by date range
     */
    // private function filterByDateRange(Collection $transactions, ?string $dateFrom, ?string $dateTo)
    // {
    //     return $transactions->filter(function ($transaction) use ($dateFrom, $dateTo) {
    //         $transactionDate = $transaction['date'];

    //         if ($dateFrom && $transactionDate < $dateFrom) {
    //             return false;
    //         }

    //         if ($dateTo && $transactionDate > $dateTo) {
    //             return false;
    //         }

    //         return true;
    //     });
    // }

    /**
     * Paginate a collection
     */
    // private function paginateCollection(Collection $collection, int $perPage, Request $request)
    // {
    //     $page = $request->get('page', 1);
    //     $offset = ($page - 1) * $perPage;

    //     $items = $collection->slice($offset, $perPage)->values();

    //     return new \Illuminate\Pagination\LengthAwarePaginator(
    //         $items,
    //         $collection->count(),
    //         $perPage,
    //         $page,
    //         [
    //             'path' => $request->url(),
    //             'pageName' => 'page',
    //             'query' => $request->query()
    //         ]
    //     );
    // }

    /**
     * Format bytes to human readable format
     */
    // private function formatBytes(int $bytes, int $precision = 2): string
    // {
    //     $units = ['B', 'KB', 'MB', 'GB'];

    //     for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
    //         $bytes /= 1024;
    //     }

    //     return round($bytes, $precision) . ' ' . $units[$i];
    // }
}
