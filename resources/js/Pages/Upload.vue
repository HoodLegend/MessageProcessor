<template>
    <AuthenticatedLayout>
        <Head title="Upload Messages" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold">Upload Message File</h2>
                            <Link
                                :href="route('messages.index')"
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded"
                            >
                                Back to Messages
                            </Link>
                        </div>

                        <div class="mb-6">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <h3 class="text-lg font-medium text-blue-900 mb-2">File Format Information</h3>
                                <p class="text-blue-700 mb-2">Upload files containing fixed-width messages with the following structure:</p>
                                <div class="bg-white p-3 rounded border font-mono text-sm">
                                    201008170000000000000004500039367306            20100417VODS2R8MMKSB   2010061705035500INTERNET  BIS     XNN
                                </div>
                                <div class="mt-2 text-sm text-blue-600">
                                    <strong>Fields:</strong> Timestamp (14) + Amount (10) + Reference (17) + Date (8) + Code (15) + Transaction Date (14) + Service (10) + Provider (8) + Status (3)
                                </div>
                            </div>
                        </div>

                        <form @submit.prevent="submitFile" enctype="multipart/form-data">
                            <div class="mb-6">
                                <label for="file" class="block text-sm font-medium text-gray-700 mb-2">
                                    Select Message File
                                </label>
                                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-gray-400 transition-colors"
                                     :class="{ 'border-blue-400 bg-blue-50': isDragOver }"
                                     @drop.prevent="handleDrop"
                                     @dragover.prevent="isDragOver = true"
                                     @dragleave.prevent="isDragOver = false">
                                    <div class="space-y-1 text-center">
                                        <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                            <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                        <div class="flex text-sm text-gray-600">
                                            <label for="file" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                                <span>Upload a file</span>
                                                <input
                                                    id="file"
                                                    ref="fileInput"
                                                    name="file"
                                                    type="file"
                                                    class="sr-only"
                                                    accept=".txt,.log,.dat"
                                                    @change="handleFileSelect"
                                                    required
                                                >
                                            </label>
                                            <p class="pl-1">or drag and drop</p>
                                        </div>
                                        <p class="text-xs text-gray-500">
                                            TXT, LOG, DAT files up to 10MB
                                        </p>
                                    </div>
                                </div>

                                <div v-if="selectedFile" class="mt-3 p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center">
                                        <svg class="h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        <div class="ml-3">
                                            <p class="text-sm font-medium text-gray-900">{{ selectedFile.name }}</p>
                                            <p class="text-sm text-gray-500">{{ formatFileSize(selectedFile.size) }}</p>
                                        </div>
                                        <button
                                            @click="removeFile"
                                            type="button"
                                            class="ml-auto text-red-600 hover:text-red-800"
                                        >
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-end">
                                <button
                                    type="submit"
                                    :disabled="!selectedFile || processing"
                                    class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
                                >
                                    <svg v-if="processing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ processing ? 'Processing...' : 'Upload and Process' }}
                                </button>
                            </div>
                        </form>

                        <!-- Processing Progress -->
                        <div v-if="processing" class="mt-6">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex items-center">
                                    <svg class="animate-spin h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span class="ml-2 text-blue-800">Processing file... This may take a few moments for large files.</span>
                                </div>
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
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const selectedFile = ref(null);
const isDragOver = ref(false);
const processing = ref(false);
const fileInput = ref(null);

const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        selectedFile.value = file;
    }
};


const handleDrop = (event) => {
    isDragOver.value = false;
    const files = event.dataTransfer.files;
    if (files.length > 0) {
        const file = files[0];
        if (isValidFileType(file)) {
            selectedFile.value = file;
            // Update the file input
            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.value.files = dt.files;
        } else {
            alert('Please select a valid file type (.txt, .log, .dat)');
        }
    }
};

const isValidFileType = (file) => {
    const validTypes = ['text/plain', 'application/octet-stream'];
    const validExtensions = ['.txt', '.log', '.dat'];

    return validTypes.includes(file.type) ||
           validExtensions.some(ext => file.name.toLowerCase().endsWith(ext));
};

const removeFile = () => {
    selectedFile.value = null;
    fileInput.value.value = '';
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';

    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const submitFile = () => {
    if (!selectedFile.value) return;

    processing.value = true;

    const formData = new FormData();
    formData.append('file', selectedFile.value);

    router.post(route('messages.store'), formData, {
        forceFormData: true,
        onFinish: () => {
            processing.value = false;
        },
        onError: () => {
            processing.value = false;
        }
    });
};
</script>
