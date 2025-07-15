<template>
       <AuthenticatedLayout>
        <Head title="Transaction Stats" />
  <div class="p-6 max-w-5xl mx-auto bg-white shadow rounded-lg">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">📊 Transaction Statistics</h1>

    <div v-if="!success" class="text-red-500 text-lg">
      {{ message ?? 'Failed to load stats.' }}
    </div>

    <div v-else>
      <!-- Basic Stats -->
      <div class="grid grid-cols-2 md:grid-cols-3 gap-6 mb-6">
        <div class="p-4 bg-blue-50 rounded shadow">
          <p class="text-sm text-gray-500">Total Records</p>
          <p class="text-2xl font-semibold text-blue-800">{{ data.total_records }}</p>
        </div>
        <div class="p-4 bg-green-50 rounded shadow">
          <p class="text-sm text-gray-500">Parsed Records</p>
          <p class="text-2xl font-semibold text-green-800">{{ data.parsed_records }}</p>
        </div>
        <div class="p-4 bg-red-50 rounded shadow">
          <p class="text-sm text-gray-500">Failed Records</p>
          <p class="text-2xl font-semibold text-red-800">{{ data.failed_records }}</p>
        </div>
        <div class="p-4 bg-gray-50 rounded shadow col-span-2 md:col-span-1">
          <p class="text-sm text-gray-500">Last Processed</p>
          <p class="text-lg text-gray-700">{{ formatDate(data.last_processed) }}</p>
        </div>
        <div class="p-4 bg-yellow-50 rounded shadow">
          <p class="text-sm text-gray-500">Processing Time</p>
          <p class="text-lg text-yellow-800">{{ data.processing_time }}s</p>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid md:grid-cols-2 gap-6">
        <div>
          <h2 class="text-lg font-semibold text-gray-700 mb-2">Message Types</h2>
          <canvas ref="messageTypeChartRef" class="w-full h-64"></canvas>
        </div>
        <div>
          <h2 class="text-lg font-semibold text-gray-700 mb-2">Statuses</h2>
          <canvas ref="statusChartRef" class="w-full h-64"></canvas>
        </div>
      </div>
    </div>
  </div>
</AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import {
  Chart,
  BarController, // <-- Required for 'bar' chart types
  BarElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend
} from 'chart.js';
import { defineProps, onMounted, ref } from 'vue';

// Register required chart.js components
Chart.register(BarElement, BarController, CategoryScale, LinearScale, Tooltip, Legend);

const props = defineProps({
  success: Boolean,
  data: Object,
  message: String
});

const messageTypeChartRef = ref(null);
const statusChartRef = ref(null);

onMounted(() => {
  if (props.success) {
    renderMessageTypeChart();
    renderStatusChart();
  }
});

function renderMessageTypeChart() {
  const ctx = messageTypeChartRef.value;
  const labels = Object.keys(props.data.message_types || {});
  const data = Object.values(props.data.message_types || {});

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Message Types',
        data,
        backgroundColor: 'rgba(59, 130, 246, 0.7)',
        borderColor: 'rgba(59, 130, 246, 1)',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { stepSize: 1 }
        }
      }
    }
  });
}

function renderStatusChart() {
  const ctx = statusChartRef.value;
  const labels = Object.keys(props.data.statuses || {});
  const data = Object.values(props.data.statuses || {});

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Statuses',
        data,
        backgroundColor: 'rgba(34, 197, 94, 0.7)',
        borderColor: 'rgba(34, 197, 94, 1)',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { stepSize: 1 }
        }
      }
    }
  });
}

function formatDate(dateString) {
  if (!dateString) return 'Never';
  return new Date(dateString).toLocaleString();
}
</script>
