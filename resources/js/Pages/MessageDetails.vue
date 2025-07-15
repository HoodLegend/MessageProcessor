<template>
    <AuthenticatedLayout>
        <Head title="Message Details" />

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold">Message Details</h2>
                            <Link
                                :href="route('messages.index')"
                                class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded"
                            >
                                Back to Messages
                            </Link>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Message Information -->
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h3 class="text-lg font-semibold mb-4 text-gray-800">Message Information</h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600">Message ID</label>
                                        <p class="mt-1 text-sm text-gray-900 font-mono break-all">{{ message.message_id }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600">Line Number</label>
                                        <p class="mt-1 text-sm text-gray-900">{{ message.line_number }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600">Parsed At</label>
                                        <p class="mt-1 text-sm text-gray-900">{{ formatDate(message.parsed_at) }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Transaction Details -->
                            <div class="bg-blue-50 rounded-lg p-4">
                                <h3 class="text-lg font-semibold mb-4 text-blue-800">Transaction Details</h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-blue-600">Amount</label>
                                        <p class="mt-1 text-lg font-bold text-blue-900">{{ formatAmount(message.amount) }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-blue-600">Reference</label>
                                        <p class="mt-1 text-sm text-blue-900 font-mono">{{ message.reference }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-blue-600">Status</label>
                                        <span :class="getStatusClass(message.status)" class="mt-1 inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                            {{ message.status }} - {{ getStatusDescription(message.status) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Timestamp Information -->
                            <div class="bg-green-50 rounded-lg p-4">
                                <h3 class="text-lg font-semibold mb-4 text-green-800">Timestamp Information</h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-green-600">Message Timestamp</label>
                                        <p class="mt-1 text-sm text-green-900">{{ formatTimestamp(message.timestamp) }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-green-600">Date</label>
                                        <p class="mt-1 text-sm text-green-900">{{ formatDate(message.date) }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-green-600">Transaction Date</label>
                                        <p class="mt-1 text-sm text-green-900">{{ formatTransactionDate(message.transaction_date) }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Service Information -->
                            <div class="bg-purple-50 rounded-lg p-4">
                                <h3 class="text-lg font-semibold mb-4 text-purple-800">Service Information</h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-purple-600">Service</label>
                                        <p class="mt-1 text-sm text-purple-900">{{ message.service }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-purple-600">Provider</label>
                                        <p class="mt-1 text-sm text-purple-900">{{ message.provider }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-purple-600">Code</label>
                                        <p class="mt-1 text-sm text-purple-900 font-mono">{{ message.code }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Raw Message -->
                        <div class="mt-6 bg-gray-100 rounded-lg p-4">
                            <h3 class="text-lg font-semibold mb-3 text-gray-800">Raw Message</h3>
                            <div class="bg-white p-3 rounded border font-mono text-sm overflow-x-auto">
                                {{ message.raw_message }}
                            </div>
                            <div class="mt-2 text-xs text-gray-600">
                                Length: {{ message.raw_message.length }} characters
                            </div>
                        </div>

                        <!-- Field Breakdown -->
                        <div class="mt-6 bg-yellow-50 rounded-lg p-4">
                            <h3 class="text-lg font-semibold mb-3 text-yellow-800">Field Breakdown</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-yellow-200">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-yellow-600 uppercase">Field</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-yellow-600 uppercase">Position</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-yellow-600 uppercase">Length</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-yellow-600 uppercase">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-yellow-200">
                                        <tr v-for="field in fieldBreakdown" :key="field.name">
                                            <td class="px-3 py-2 text-sm font-medium text-yellow-900">{{ field.name }}</td>
                                            <td class="px-3 py-2 text-sm text-yellow-800">{{ field.position }}</td>
                                            <td class="px-3 py-2 text-sm text-yellow-800">{{ field.length }}</td>
                                            <td class="px-3 py-2 text-sm text-yellow-900 font-mono">{{ field.value }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    message: {
        type: Object,
        required: true
    }
});

const fieldBreakdown = computed(() => {
    const structure = [
        { name: 'Timestamp', start: 0, length: 14 },
        { name: 'Amount', start: 14, length: 10 },
        { name: 'Reference', start: 24, length: 17 },
        { name: 'Date', start: 41, length: 8 },
        { name: 'Code', start: 49, length: 15 },
        { name: 'Transaction Date', start: 64, length: 14 },
        { name: 'Service', start: 78, length: 10 },
        { name: 'Provider', start: 88, length: 8 },
        { name: 'Status', start: 96, length: 3 }
    ];

    return structure.map(field => ({
        name: field.name,
        position: `${field.start}-${field.start + field.length - 1}`,
        length: field.length,
        value: props.message.raw_message.substring(field.start, field.start + field.length).trim()
    }));
});

const formatTimestamp = (timestamp) => {
    if (!timestamp || timestamp.length < 14) return timestamp;

    const year = timestamp.substring(0, 4);
    const month = timestamp.substring(4, 6);
    const day = timestamp.substring(6, 8);
    const hour = timestamp.substring(8, 10);
    const minute = timestamp.substring(10, 12);
    const second = timestamp.substring(12, 14);

    return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
};

const formatDate = (dateStr) => {
    if (!dateStr) return 'N/A';

    if (dateStr.includes('T')) {
        // ISO format
        return new Date(dateStr).toLocaleString();
    } else if (dateStr.length === 8) {
        // YYYYMMDD format
        const year = dateStr.substring(0, 4);
        const month = dateStr.substring(4, 6);
        const day = dateStr.substring(6, 8);
        return `${year}-${month}-${day}`;
    }

    return dateStr;
};

const formatTransactionDate = (transactionDate) => {
    if (!transactionDate || transactionDate.length < 14) return transactionDate;

    return formatTimestamp(transactionDate);
};

const formatAmount = (amount) => {
    if (!amount) return '$0.00';

    const numAmount = parseInt(amount) / 100;
    return numAmount.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD'
    });
};

const getStatusClass = (status) => {
    const statusClasses = {
        'XNN': 'bg-red-100 text-red-800',
        'OK': 'bg-green-100 text-green-800',
        'PEN': 'bg-yellow-100 text-yellow-800',
        'ERR': 'bg-red-100 text-red-800'
    };

    return statusClasses[status] || 'bg-gray-100 text-gray-800';
};

const getStatusDescription = (status) => {
    const descriptions = {
        'XNN': 'Failed/Declined',
        'OK': 'Success',
        'PEN': 'Pending',
        'ERR': 'Error'
    };

    return descriptions[status] || 'Unknown';
};
</script>
