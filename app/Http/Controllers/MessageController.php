<?php

namespace App\Http\Controllers;

use App\Services\MessageProcessorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MessageController extends Controller
{
    /**
     * Display a list of all CSV files and their data
     */
    public function index(Request $request)
    {
        $csvFiles = $this->getCsvFiles();
        $selectedFile = $request->get('file');
        $search = $request->get('search');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $perPage = $request->get('per_page', 25);

        $transactions = collect();
        $totalAmount = 0;
        $fileStats = [];

        if ($selectedFile && Storage::exists("csv_exports/{$selectedFile}")) {
            $transactions = $this->parseCSVFile($selectedFile);

            // Apply filters
            if ($search) {
                $transactions = $this->filterTransactions($transactions, $search);
            }

            if ($dateFrom || $dateTo) {
                $transactions = $this->filterByDateRange($transactions, $dateFrom, $dateTo);
            }

            // Calculate stats
            $totalAmount = $transactions->sum(function ($transaction) {
                return (float) $transaction['amount'];
            });

            $fileStats = [
                'total_records' => $transactions->count(),
                'total_amount' => $totalAmount,
                'date_range' => [
                    'from' => $transactions->min('date'),
                    'to' => $transactions->max('date')
                ],
                'unique_mobiles' => $transactions->pluck('mobile_number')->unique()->count()
            ];
        }

        // Paginate results
        $paginatedTransactions = $this->paginateCollection($transactions, $perPage, $request);

        return inertia('Messages', [
            'csvFiles' => $csvFiles,
            'selectedFile' => $selectedFile,
            'data' => $paginatedTransactions,
            'filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'per_page' => $perPage
            ],
            'fileStats' => $fileStats,
            'totalAmount' => $totalAmount
        ]);
    }

    /**
     * Download a specific CSV file
     */
    public function download(Request $request)
    {
        $fileName = $request->get('file');

        if (!$fileName || !Storage::exists("csv_exports/{$fileName}")) {
            return redirect()->back()->with('error', 'File not found.');
        }

        return Storage::download("csv_exports/{$fileName}");
    }

    /**
     * Export filtered data to CSV
     */
    public function export(Request $request)
    {
        $selectedFile = $request->get('file');
        $search = $request->get('search');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        if (!$selectedFile || !Storage::exists("csv_exports/{$selectedFile}")) {
            return redirect()->back()->with('error', 'File not found.');
        }

        $transactions = $this->parseCSVFile($selectedFile);

        // Apply filters
        if ($search) {
            $transactions = $this->filterTransactions($transactions, $search);
        }

        if ($dateFrom || $dateTo) {
            $transactions = $this->filterByDateRange($transactions, $dateFrom, $dateTo);
        }

        // Generate CSV content
        $csvContent = "File,Line,Date,Amount,Mobile Number,Transaction ID\n";
        foreach ($transactions as $transaction) {
            $csvContent .= sprintf(
                "%s,%d,%s,%s,%s,%s\n",
                $transaction['file'],
                $transaction['line'],
                $transaction['date'],
                $transaction['amount'],
                $transaction['mobile_number'],
                $transaction['transaction_id']
            );
        }

        $fileName = 'filtered_transactions_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csvContent)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"");
    }

    /**
     * API endpoint to get transaction data as JSON
     */
    public function api(Request $request)
    {
        $selectedFile = $request->get('file');

        if (!$selectedFile || !Storage::exists("csv_exports/{$selectedFile}")) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $transactions = $this->parseCSVFile($selectedFile);

        // Apply filters
        $search = $request->get('search');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        if ($search) {
            $transactions = $this->filterTransactions($transactions, $search);
        }

        if ($dateFrom || $dateTo) {
            $transactions = $this->filterByDateRange($transactions, $dateFrom, $dateTo);
        }

        return response()->json([
            'data' => $transactions->values(),
            'meta' => [
                'total' => $transactions->count(),
                'total_amount' => $transactions->sum(function ($t) {
                    return (float) $t['amount']; }),
                'file' => $selectedFile
            ]
        ]);
    }

    /**
     * Delete a CSV file
     */
    public function delete(Request $request)
    {
        $fileName = $request->get('file');

        if (!$fileName || !Storage::exists("csv_exports/{$fileName}")) {
            return redirect()->back()->with('error', 'File not found.');
        }

        Storage::delete("csv_exports/{$fileName}");

        return redirect()->route('dat-transactions.index')
            ->with('message', "File '{$fileName}' has been deleted successfully.");
    }



    public function getCsvData(Request $request)
    {
        try {
            $directory = 'exports';
            $files = Storage::files($directory);

            $csvFiles = array_filter($files, function ($file) {
                return pathinfo($file, PATHINFO_EXTENSION) === 'csv';
            });

            $data = [];
            $fileName = '';

            if (!empty($csvFiles)) {
                // Get the latest file
                usort($csvFiles, function ($a, $b) {
                    return Storage::lastModified($b) - Storage::lastModified($a);
                });

                $latestFile = $csvFiles[0];
                $fileName = basename($latestFile);
                $csvContent = Storage::get($latestFile);

                $lines = explode("\n", trim($csvContent));
                $header = str_getcsv(array_shift($lines));

                foreach ($lines as $line) {
                    if (trim($line)) {
                        $row = str_getcsv($line);
                        $data[] = [
                            'date' => $row[0] ?? '',
                            'amount' => $row[1] ?? '',
                            'mobile_number' => $row[2] ?? '',
                            'transaction_id' => $row[3] ?? '',
                        ];
                    }
                }
            }

            return Inertia::render('Messages', [
                'data' => $data,
                'fileName' => $fileName,
                'totalRecords' => count($data),
                'currentFilePath' => $latestFile ?? null,
            ]);

        } catch (\Exception $e) {
            return Inertia::render('Messages', [
                'data' => [],
                'fileName' => '',
                'totalRecords' => 0,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function downloadCsv($filename)
    {
        $filePath = 'exports/' . $filename;

        if (!Storage::exists($filePath)) {
            abort(404, 'File not found');
        }

        // Security check - only allow CSV files from exports directory
        if (pathinfo($filename, PATHINFO_EXTENSION) !== 'csv') {
            abort(403, 'Invalid file type');
        }

        return Storage::download($filePath, $filename);
    }

    /**
     * Parse a CSV file and return collection of transactions
     */
    private function parseCSVFile(string $fileName): Collection
    {
        $content = Storage::get("csv_exports/{$fileName}");
        $lines = explode("\n", $content);

        // Remove header line and empty lines
        $dataLines = array_filter(array_slice($lines, 1), function ($line) {
            return !empty(trim($line));
        });

        $transactions = collect();

        foreach ($dataLines as $line) {
            $fields = str_getcsv($line);

            if (count($fields) >= 6) {
                $transactions->push([
                    'date' => $fields[0],
                    'amount' => $fields[1],
                    'mobile_number' => $fields[2],
                    'transaction_id' => $fields[3]
                ]);
            }
        }

        return $transactions;
    }

    /**
     * Filter transactions based on search term
     */
    private function filterTransactions(Collection $transactions, string $search)
    {
        return $transactions->filter(function ($transaction) use ($search) {
            return stripos($transaction['mobile_number'], $search) !== false ||
                stripos($transaction['transaction_id'], $search) !== false ||
                stripos($transaction['amount'], $search) !== false ||
                stripos($transaction['date'], $search) !== false;
        });
    }

    /**
     * Filter transactions by date range
     */
    private function filterByDateRange(Collection $transactions, ?string $dateFrom, ?string $dateTo)
    {
        return $transactions->filter(function ($transaction) use ($dateFrom, $dateTo) {
            $transactionDate = $transaction['date'];

            if ($dateFrom && $transactionDate < $dateFrom) {
                return false;
            }

            if ($dateTo && $transactionDate > $dateTo) {
                return false;
            }

            return true;
        });
    }

    /**
     * Paginate a collection
     */
    private function paginateCollection(Collection $collection, int $perPage, Request $request)
    {
        $page = $request->get('page', 1);
        $offset = ($page - 1) * $perPage;

        $items = $collection->slice($offset, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $collection->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => 'page',
                'query' => $request->query()
            ]
        );
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
