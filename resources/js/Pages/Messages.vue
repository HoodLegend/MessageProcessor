<template>

    <Head title="Messages" />
    <AuthenticatedLayout>
        <!-- Header Section -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8">
            <!-- Header with Title and Button -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                <h2 class="text-2xl font-bold text-gray-900">Transaction Data</h2>
                <div class="self-end sm:self-auto">
                    <button @click="refreshData" :disabled="loading"
                        class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                        <svg v-if="loading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                        {{ loading ? 'Loading...' : 'Refresh Data' }}
                    </button>
                </div>
            </div>

            <!-- Info Bar -->
            <div class="bg-blue-50 border border-blue-200 text-blue-700 px-6 py-4 rounded mb-6 max-w-4xl mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div><strong>File:</strong> {{ fileName || 'Latest transactions' }}</div>
                    <div><strong>Records:</strong> {{ totalRecords }}</div>
                    <div><strong>Last Updated:</strong> {{ lastUpdated }}</div>
                </div>
            </div>

            <!-- Error Display -->
            <div v-if="error"
                class="bg-red-50 border border-red-200 text-red-700 px-6 py-4 rounded mb-6 max-w-4xl mx-auto">
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
                                    <th class="px-4 py-2 font-semibold text-gray-700">Date</th>
                                    <th class="px-4 py-2 font-semibold text-gray-700">Amount</th>
                                    <th class="px-4 py-2 font-semibold text-gray-700">Phone Number</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- DataTables will populate this -->
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>

    </AuthenticatedLayout>


</template>

<script>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import $ from 'jquery';
import 'datatables.net';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';


export default {
    name: 'Messages',
    components: {
        AuthenticatedLayout,
        Head
    },
    props: {
        data: {
            type: Array,
            default: () => []
        },
        fileName: {
            type: String,
            default: ''
        },
        totalRecords: {
            type: Number,
            default: 0
        }
    },
    setup(props) {
        const loading = ref(false)
        const error = ref(null)
        const lastUpdated = ref(new Date().toLocaleString())
        let dataTable = null

        const initializeDataTable = () => {
            if (dataTable) {
                dataTable.destroy()
            }

            dataTable = $('#transactionsTable').DataTable({
                data: props.data || [],
                columns: [
                    {
                        data: 'transaction_id',
                        title: 'Transaction ID',
                        render: function (data) {
                            return data || 'N/A'
                        }
                    },
                    {
                        data: 'date',
                        title: 'Date',
                        render: function (data) {
                            return formatDate(data)
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
                            return formatPhoneNumber(data)
                        }
                    },
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[1, 'desc']], // Sort by date descending
                responsive: true,
                processing: false, // No need for processing indicator with Inertia
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
                initComplete: function () {
                    // Custom styling after table initialization
                    $('.dataTables_length select').addClass('form-select rounded border-gray-300')
                    $('.dataTables_filter input').addClass('form-input rounded border-gray-300')
                }
            })

            console.log('DataTable initialized with', props.data?.length || 0, 'records')
        }

        const refreshData = () => {
            loading.value = true
            error.value = null

            // Use Inertia to reload the page with fresh data
            router.reload({
                onSuccess: () => {
                    loading.value = false
                    lastUpdated.value = new Date().toLocaleString()
                    console.log('Data refreshed via Inertia')
                },
                onError: (errors) => {
                    loading.value = false
                    error.value = 'Failed to refresh data'
                    console.error('Inertia reload error:', errors)
                }
            })
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
                currency: 'NAD'
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

        // Watch for changes in props.data (when Inertia updates the data)
        watch(() => props.data, (newData) => {
            console.log('Data prop changed, updating DataTable with', newData?.length || 0, 'records')
            if (dataTable) {
                dataTable.clear().rows.add(newData || []).draw()
                lastUpdated.value = new Date().toLocaleString()
            }
        }, { deep: true })

        onMounted(() => {
            console.log('Component mounted with data:', props.data?.length || 0, 'records')
            initializeDataTable()
        })

        onUnmounted(() => {
            if (dataTable) {
                dataTable.destroy()
            }
        })

        return {
            loading,
            error,
            lastUpdated,
            refreshData,
            // Expose props as computed values for template
            fileName: props.fileName,
            totalRecords: props.totalRecords || props.data?.length || 0
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
