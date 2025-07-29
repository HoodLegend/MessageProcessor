<template>
    <AuthenticatedLayout>

        <Head title="Messages" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold">Message Parser</h2>
                            <Link :href="route('messages.upload')"
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Upload File
                            </Link>
                        </div>

                        <!-- Filters -->
                        <div class="mb-6 flex space-x-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Filter by Date (YYYYMMDD)</label>
                                <input v-model="filterDate" type="text" placeholder="20100417"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    @keyup.enter="filterMessages" />
                            </div>
                            <div class="flex items-end">
                                <button @click="filterMessages"
                                :disabled="!filterDate"
                                    class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                    Filter
                                </button>
                                <button @click="clearFilter"
                                :disabled="!filterDate"
                                    class="ml-2 bg-red-500 hover:bg-red-400 text-gray-800 font-bold py-2 px-4 rounded">
                                    Clear
                                </button>
                            </div>
                        </div>

                        <!-- Success/Error Messages -->
                        <div v-if="$page.props.flash.success"
                            class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                            <strong>Success!</strong> {{ $page.props.flash.success.message }}
                            <br>Processed {{ $page.props.flash.success.count }} messages from {{
                                $page.props.flash.success.filename }}
                        </div>

                        <div v-if="$page.props.flash.error"
                            class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                            <strong>Error!</strong> {{ $page.props.flash.error }}
                        </div>

                        <!-- Messages Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Transaction ID
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Transaction Time
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Transaction Amount
                                        </th>
                                                                                <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Phone Number
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="message in data" :key="message.transaction_id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ message.transaction_id }}...
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ formatTimestamp(message.date) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ formatAmount(message.amount) }}
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ message.mobile_number }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <Link :href="route('messages.show', message.transaction_id)"
                                                class="text-indigo-600 hover:text-indigo-900">
                                            View Details
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="data.length === 0" class="text-center py-8 text-gray-500">
                            No messages found. Upload a file to get started.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    data: {
        type: Array,
        default: () => []
    },
    filters: {
        type: Object,
        default: () => ({})
    }
});



const filterDate = ref('');

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



const formatAmount = (amount) => {
    if (!amount) return '0.00';

    // Assuming the amount is in cents/smallest unit
    const numAmount = parseInt(amount) / 100;
    return numAmount.toLocaleString('en-US', {
        style: 'currency',
        currency: 'NAM'
    });
};


const filterMessages = () => {
    router.get(route('messages.index'), {
        date: filterDate.value
    }, {
        preserveState: true,
        preserveScroll: true
    });
};

const clearFilter = () => {
    filterDate.value = '';
    router.get(route('messages.index'), {}, {
        preserveState: true,
        preserveScroll: true
    });
};
</script>
