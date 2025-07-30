<template>
    <AuthenticatedLayout>

        <Head title="Messages" />
    <div>
        <!-- Header Section -->
        <div class="mb-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-2xl font-bold text-gray-900">Transaction Data</h2>
                <button
                    @click="refreshData"
                    :disabled="loading"
                    class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                >
                    <svg v-if="loading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ loading ? 'Loading...' : 'Refresh Data' }}
                </button>
            </div>

            <!-- Info Bar -->
            <div v-if="fileName" class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded mb-4">
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <span><strong>File:</strong> {{ fileName }}</span>
                    <span><strong>Records:</strong> {{ totalRecords }}</span>
                    <span><strong>Last Updated:</strong> {{ lastUpdated }}</span>
                </div>
            </div>

            <!-- Error Display -->
            <div v-if="error" class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
                <p>{{ error }}</p>
            </div>
        </div>

        <!-- DataTable Container -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <table id="transactionsTable" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Phone Number</th>
                        <th>Source File</th>
                        <th>Line</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- DataTables will populate this -->
                </tbody>
            </table>
        </div>
    </div>

    </AuthenticatedLayout>
</template>

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import $ from 'jquery'
import 'datatables.net'
import 'datatables.net-dt/css/dataTables.dataTables.css'

export default {
    name: 'TransactionDataTable',
    setup() {
        const loading = ref(false)
        const error = ref(null)
        const fileName = ref('')
        const totalRecords = ref(0)
        const lastUpdated = ref('')
        let dataTable = null

        const initializeDataTable = () => {
            if (dataTable) {
                dataTable.destroy()
            }

            dataTable = $('#transactionsTable').DataTable({
                data: [],
                columns: [
                    {
                        data: 'transaction_id',
                        title: 'Transaction ID',
                        render: function(data) {
                            return data || 'N/A'
                        }
                    },
                    {
                        data: 'date',
                        title: 'Date',
                        render: function(data) {
                            return formatDate(data)
                        }
                    },
                    {
                        data: 'amount',
                        title: 'Amount',
                        render: function(data) {
                            return formatAmount(data)
                        },
                        className: 'text-right'
                    },
                    {
                        data: 'mobile_number',
                        title: 'Phone Number',
                        render: function(data) {
                            return formatPhoneNumber(data)
                        }
                    },
                    {
                        data: 'file',
                        title: 'Source File',
                        render: function(data) {
                            return getFileName(data)
                        }
                    },
                    {
                        data: 'line',
                        title: 'Line #',
                        className: 'text-center'
                    }
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[1, 'desc']], // Sort by date descending
                responsive: true,
                processing: true,
                language: {
                    emptyTable: "No transaction data available",
                    info: "Showing _START_ to _END_ of _TOTAL_ transactions",
                    infoEmpty: "Showing 0 to 0 of 0 transactions",
                    infoFiltered: "(filtered from _MAX_ total transactions)",
                    lengthMenu: "Show _MENU_ transactions per page",
                    search: "Search transactions:",
                    zeroRecords: "No matching transactions found"
                },
                dom: '<"flex justify-between items-center mb-4"<"flex items-center gap-2"l><"flex items-center gap-2"f>>rtip',
                initComplete: function() {
                    // Custom styling after table initialization
                    $('.dataTables_length select').addClass('form-select rounded border-gray-300')
                    $('.dataTables_filter input').addClass('form-input rounded border-gray-300')
                }
            })
        }

        const loadData = async () => {
            loading.value = true
            error.value = null

            try {
                const response = await axios.get('/transactions/csv-data')
                const data = response.data.data

                fileName.value = response.data.file_name
                totalRecords.value = response.data.total_records
                lastUpdated.value = new Date().toLocaleString()

                // Update DataTable with new data
                if (dataTable) {
                    dataTable.clear().rows.add(data).draw()
                }

            } catch (err) {
                error.value = err.response?.data?.error || 'Failed to load transaction data'
                console.error('Error loading CSV data:', err)
            } finally {
                loading.value = false
            }
        }

        const refreshData = () => {
            loadData()
        }

        const formatDate = (dateString) => {
            if (!dateString) return 'N/A'
            try {
                const date = new Date(dateString)
                return date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                })
            } catch {
                return dateString
            }
        }

        const formatAmount = (amount) => {
            if (!amount) return 'N/A'
            const cleanAmount = amount.toString().replace(/\*/g, '')
            const numAmount = parseFloat(cleanAmount)
            if (isNaN(numAmount)) return amount

            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(numAmount)
        }

        const formatPhoneNumber = (phone) => {
            if (!phone) return 'N/A'
            return phone.toString().replace(/\*/g, '')
        }

        const getFileName = (filePath) => {
            if (!filePath) return 'N/A'
            return filePath.split('/').pop() || filePath
        }

        // Auto-refresh functionality
        let refreshInterval = null
        const startAutoRefresh = () => {
            refreshInterval = setInterval(() => {
                if (!loading.value) {
                    loadData()
                }
            }, 60000) // Refresh every minute
        }

        const stopAutoRefresh = () => {
            if (refreshInterval) {
                clearInterval(refreshInterval)
                refreshInterval = null
            }
        }

        onMounted(async () => {
            initializeDataTable()
            await loadData()
            startAutoRefresh()
        })

        onUnmounted(() => {
            stopAutoRefresh()
            if (dataTable) {
                dataTable.destroy()
            }
        })

        return {
            loading,
            error,
            fileName,
            totalRecords,
            lastUpdated,
            refreshData
        }
    }
}
</script>

<style>
/* Custom DataTables styling */
.dataTables_wrapper {
    font-family: inherit;
}

.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    margin-bottom: 1rem;
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
