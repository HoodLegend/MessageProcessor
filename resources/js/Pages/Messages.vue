<template>

    <Head title="Transactions" />
    <AuthenticatedLayout>
        <!-- Header Section -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8">
            <!-- Header with Title and Controls -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                <h2 class="text-2xl font-bold text-gray-900">Transactions</h2>
                <div class="flex flex-col sm:flex-row gap-2">
                    <!-- Date Filter -->
                    <div class="flex items-center gap-2">
                        <label for="dateFilter" class="text-sm font-medium text-gray-700">Filter by Date:</label>
                        <select id="dateFilter" v-model="selectedDate" @change="onDateChange"
                            :disabled="availableDates.length === 0" class="form-select rounded border-gray-300 text-sm"
                            :class="{ 'bg-gray-100 cursor-not-allowed': availableDates.length === 0 }">
                            <option value="">All Dates</option>
                            <option v-if="availableDates.length === 0" value="" disabled>
                                No dates available
                            </option>
                            <option v-for="date in availableDates" :key="date.value" :value="date.value">
                                {{ date.label }} ({{ date.count }} records)
                            </option>
                        </select>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-2">
                        <button @click="refreshData" :disabled="loading"
                            class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                            <svg v-if="loading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4" />
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                            </svg>
                            {{ loading ? 'Loading...' : 'Refresh' }}
                        </button>

                        <button @click="downloadCurrentData" :disabled="!hasData || loading"
                            class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 disabled:opacity-50 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                                </path>
                            </svg>
                            Download CSV
                        </button>
                    </div>
                </div>
            </div>

            <!-- Info Bar -->
            <div class="bg-blue-50 border border-blue-200 text-blue-700 px-6 py-4 rounded mb-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                    <div><strong>Selected Date:</strong> {{ selectedDate ? formatDisplayDate(selectedDate) : 'All Dates'
                        }}</div>
                    <div><strong>Available Files:</strong> {{ availableDates.length }}</div>
                    <div><strong>Total Records:</strong> {{ totalRecords }}</div>
                    <div><strong>Last Updated:</strong> {{ lastUpdated }}</div>
                </div>
            </div>

            <!-- Date Range Quick Filters -->
            <div class="flex flex-wrap gap-2 mb-6">
                <button v-for="quickFilter in quickFilters" :key="quickFilter.value"
                    @click="applyQuickFilter(quickFilter.value)" :class="[
                        'px-3 py-1 text-xs rounded-full border transition-colors',
                        quickFilter.active
                            ? 'bg-blue-100 border-blue-300 text-blue-700'
                            : 'bg-gray-100 border-gray-300 text-gray-700 hover:bg-gray-200'
                    ]">
                    {{ quickFilter.label }}
                </button>
            </div>

            <!-- Error Display -->
            <div v-if="error" class="bg-red-50 border border-red-200 text-red-700 px-6 py-4 rounded mb-6">
                <p>{{ error }}</p>
            </div>
        </div>

        <!-- DataTable Container -->
        <div class="py-8 px-4 sm:px-6 lg:px-8">
            <div class="max-w-7xl mx-auto">
                <div class="bg-white shadow-lg rounded-lg overflow-hidden p-6">
                    <div class="overflow-x-auto">
                        <table id="transactionsTable" class="stripe hover w-full text-sm text-left" style="width:100%">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-4 py-2 font-semibold text-gray-700">Transaction ID</th>
                                    <th class="px-4 py-2 font-semibold text-gray-700">Transaction Date</th>
                                    <th class="px-4 py-2 font-semibold text-gray-700">Transaction Time</th>
                                    <th class="px-4 py-2 font-semibold text-gray-700">Amount</th>
                                    <th class="px-4 py-2 font-semibold text-gray-700">Phone Number</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- DataTables will populate this via server-side processing -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import $ from 'jquery';
import 'datatables.net';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    availableDates: {
        type: Array,
        default: () => []
    },
    initialDate: {
        type: String,
        default: ''
    },
    totalRecords: {
        type: Number,
        default: 0
    }
})

// Reactive Data
const selectedDate = ref(props.initialDate)
const loading = ref(false)
const error = ref('')
const lastUpdated = ref(new Date().toLocaleString())
const hasRecords = ref(false)
const dataTable = ref(null)
const filter = ref(false);

// Computed properties
const hasData = computed(() => /* dataTable.value && dataTable.value.data().count() > 0 */ hasRecords.value)

const quickFilters = computed(() => {
    const today = new Date().toISOString().split('T')[0].replace(/-/g, '')
    const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0].replace(/-/g, '')
    const lastWeek = new Date(Date.now() - 7 * 86400000).toISOString().split('T')[0].replace(/-/g, '')
    const thisMonth = new Date().toISOString().slice(0, 7).replace('-', '');
    return [
        { label: 'All', value: '', active: selectedDate.value === '' },
        { label: 'Today', value: today, active: selectedDate.value === today },
        { label: 'Yesterday', value: yesterday, active: selectedDate.value === yesterday},
        { label: 'Last 7 Days', value: lastWeek, active: selectedDate.value === lastWeek},
        { label: 'This Month', value: thisMonth, active: selectedDate.value === thisMonth}
    ]
})

const getCsrfToken = () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (!token) {
        console.warn('CSRF token not found. Page may need refresh.');
        return '';
    }
    return token;
}

const initializeDataTable = () => {
    if (dataTable.value) {
        dataTable.value.destroy()
    }

    dataTable.value = $('#transactionsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: route('transactions.data'),
            type: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]')?.attr('content')
            },
            data: function(d) {
                // Add custom filters to the request
                d.date_filter = selectedDate.value
                d._token = getCsrfToken();
                return d
            },
            dataSrc: function(json) {
                // Update UI with server response data
                lastUpdated.value = new Date().toLocaleString()
                error.value = json.error || ''
                hasRecords.value = json.data.length > 0
                return json.data
            },
            error: function (xhr, error, thrown) {
                console.error('DataTable AJAX error:', error)
                error.value = 'Failed to load transaction data. Please try again.'

                if (xhr.status === 419) {
                    error.value = 'Session expired. Refreshing page...';
                    // Force page refresh to get new CSRF token
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    error.value = `Error ${xhr.status}: Failed to load data. Please try again.`;
                }
            }
        },
        columns: [
            {
                data: 'transaction_id',
                title: 'Transaction ID',
                render: function (data) {
                    return data || 'N/A'
                }
            },
            {
                data: 'transaction_date',
                title: 'Transaction Date',
                render: function (data) {
                    return formatDate(data)
                }
            },
            {
                data: 'transaction_time',
                title: 'Transaction Time',
                render: function (data) {
                    return formatTime(data)
                }
            },
            {
                data: 'amount',
                title: 'Amount',
                render: function (data) {
                    return formatAmount(data)
                },
                className: 'text-right'
            },
            {
                data: 'mobile_number',
                title: 'Phone Number',
                render: function (data) {
                    return data
                }
            }
        ],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        order: [[1, 'desc']], // Sort by date descending
        responsive: true,
        language: {
            emptyTable: "No transaction data available for selected date",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions",
            infoEmpty: "Showing 0 to 0 of 0 transactions",
            infoFiltered: "(filtered from _MAX_ total transactions)",
            lengthMenu: "Show _MENU_ transactions per page",
            search: "Search transactions:",
            zeroRecords: "No matching transactions found",
            // processing: true
        },
        dom: '<"flex justify-between items-center mb-4"<"flex items-center gap-2"l><"flex items-center gap-2"f>>rtip',
        initComplete: function () {
            $('.dataTables_length select').addClass('form-select rounded border-gray-300')
            $('.dataTables_filter input').addClass('form-input rounded border-gray-300')
        }
    })
}

// listen for the date changes based on the filter selected.
const onDateChange = () => {
    loading.value = true;
    error.value = '';

    // Reload the DataTable with new date filter
    if (dataTable.value) {
        dataTable.value.ajax.reload((json) => {
            loading.value = false;
            // Optional: Handle empty results
            if (json && json.data && json.data.length === 0) {
                // You could show a message or update UI state here
                console.log('No data found for selected date');
            }
        }, false); // false = don't reset paging
    } else {
        // Handle case where dataTable is not initialized
        loading.value = false;
        error.value = 'DataTable not initialized';
    }
}

// apply filter for which date data to display.
// const applyQuickFilter = (filterValue) => {
//     selectedDate.value = filterValue
//     onDateChange()
// }

const onQuickFilterClick = (filterValue) => {
    selectedDate.value = filterValue;
    onDateChange(); // Trigger the same logic as the dropdown
}

const applyQuickFilter = (dateValue = null) => {
    if (dateValue !== null) {
        selectedDate.value = dateValue;
    }

    loading.value = true;
    error.value = '';

    // Reload the DataTable with new date filter
    if (dataTable.value) {
        dataTable.value.ajax.reload(() => {
            loading.value = false;
        }, false);
    } else {
        loading.value = false;
        error.value = 'DataTable not initialized';
    }
}

// refreshes page incase of new updates.
const refreshData = () => {
    loading.value = true
    error.value = ''

    // Refresh available dates and reload table
    router.reload({
        onSuccess: () => {
            if (dataTable.value) {
                dataTable.value.ajax.reload(() => {
                    loading.value = false
                })
            } else {
                loading.value = false
            }
        },
        onError: () => {
            loading.value = false
            error.value = 'Failed to refresh data. Please try again.'
        }
    })
}

// download the data from the selected file.
const downloadCurrentData = () => {
    const params = new URLSearchParams({
        date_filter: selectedDate.value || '',
        format: 'csv'
    })

    window.open(route('transactions.export') + '?' + params.toString(), '_blank')
}

// Formatting functions that format the date, amount, phonenumber, time for better representation.
const formatDate = (dateString) => {
    if (!dateString || dateString === 'N/A') return 'N/A'

    try {
        const date = new Date(dateString)
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        })
    } catch (e) {
        return dateString
    }
}

const formatDisplayDate = (dateString) => {
    if (!dateString) return ''

    try {
        // Convert YYYYMMDD to YYYY-MM-DD for parsing
        const formatted = dateString.replace(/(\d{4})(\d{2})(\d{2})/, '$1-$2-$3')
        const date = new Date(formatted)
        return date.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        })
    } catch (e) {
        return dateString
    }
}

const formatTime = (timeString) => {
    if (!timeString || timeString === 'N/A') return 'N/A'

    const clean = timeString.toString().trim()

    // Case: already formatted
    if (/^\d{2}:\d{2}:\d{2}$/.test(clean)) {
        return clean
    }

    // Case: HHMMSS (6 digits)
    if (/^\d{6}$/.test(clean)) {
        const h = clean.slice(0, 2)
        const m = clean.slice(2, 4)
        const s = clean.slice(4, 6)
        return `${h}:${m}:${s}`
    }

    // Case: YYYYMMDDHHMMSSxx (length ≥ 14)
    if (/^\d{14,}$/.test(clean)) {
        const h = clean.slice(8, 10)
        const m = clean.slice(10, 12)
        const s = clean.slice(12, 14)
        return `${h}:${m}:${s}`
    }

    return timeString
}



const formatAmount = (amount) => {
    if (!amount || amount === 'N/A') return 'N/A'

    try {
        const numAmount = parseFloat(amount)
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'NAD',
            minimumFractionDigits: 2
        }).format(numAmount)
    } catch (e) {
        return amount
    }
}

const formatPhoneNumber = (phone) => {
    if (!phone || phone === 'N/A') return 'N/A'

    const cleaned = phone.toString().replace(/\D/g, '')

    if (cleaned.length === 10) {
        return `(${cleaned.slice(0, 3)}) ${cleaned.slice(3, 6)}-${cleaned.slice(6)}`
    }

    return phone
}

// Lifecycle
onMounted(() => {
    setTimeout(() => {
initializeDataTable()
    }, 100);

});

onUnmounted(() => {
    if (dataTable.value) {
        dataTable.value.destroy()
    }
})
</script>

<style>
/* Custom DataTables styling */
.dataTables_wrapper {
    font-family: inherit;
}

.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    margin-bottom: 1rem;
    padding:1rem;
}

.dataTables_wrapper .dataTables_filter input,
.dataTables_wrapper .dataTables_length select {
    padding: 0.375rem 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    font-size: 0.875rem;
}

.dataTables_wrapper .dataTables_filter input:focus,
.dataTables_wrapper .dataTables_length select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

table.dataTable {
    border-collapse: separate !important;
    border-spacing: 0;
}

table.dataTable thead th {
    background-color: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
    font-weight: 600;
    color: #374151;
    padding: 0.75rem 1.5rem;
}

table.dataTable tbody td {
    padding: 0.75rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

table.dataTable tbody tr:hover {
    background-color: #f9fafb;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 0.375rem 0.75rem;
    margin: 0 0.125rem;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    background: white;
    color: #374151;
}

.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
}

.dataTables_wrapper .dataTables_info {
    color: #6b7280;
    font-size: 0.875rem;
}
</style>
