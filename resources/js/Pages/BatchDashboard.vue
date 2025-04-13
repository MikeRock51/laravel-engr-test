<template>
    <AuthenticatedLayout>
        <Head title="Batch Dashboard" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h1 class="text-2xl font-semibold mb-6">Batch Optimization Dashboard</h1>

                        <!-- Summary Stats Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <div class="bg-blue-50 p-4 rounded-lg shadow border border-blue-100">
                                <h3 class="text-lg font-semibold text-blue-800 mb-2">Total Batches</h3>
                                <p class="text-3xl font-bold">{{ summary.totalBatches }}</p>
                                <p class="text-sm text-gray-600 mt-2">
                                    Across {{ summary.insurerCount }} insurers
                                </p>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg shadow border border-green-100">
                                <h3 class="text-lg font-semibold text-green-800 mb-2">Total Claims Batched</h3>
                                <p class="text-3xl font-bold">{{ summary.totalClaims }}</p>
                                <p class="text-sm text-gray-600 mt-2">
                                    Average {{ summary.avgClaimsPerBatch }} claims per batch
                                </p>
                            </div>
                            <div class="bg-indigo-50 p-4 rounded-lg shadow border border-indigo-100">
                                <h3 class="text-lg font-semibold text-indigo-800 mb-2">Estimated Cost Savings</h3>
                                <p class="text-3xl font-bold">${{ summary.costSavings.toFixed(2) }}</p>
                                <p class="text-sm text-gray-600 mt-2">
                                    {{ summary.savingsPercentage }}% lower than unoptimized processing
                                </p>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="bg-gray-50 p-4 rounded-lg mb-6 border border-gray-200">
                            <h3 class="text-lg font-semibold mb-3">Filters</h3>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Insurer</label>
                                    <select
                                        v-model="filters.insurer"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        @change="fetchBatchData"
                                    >
                                        <option value="">All Insurers</option>
                                        <option v-for="insurer in insurers" :key="insurer.id" :value="insurer.id">
                                            {{ insurer.name }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                                    <select
                                        v-model="filters.dateRange"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        @change="fetchBatchData"
                                    >
                                        <option value="7">Last 7 days</option>
                                        <option value="30">Last 30 days</option>
                                        <option value="90">Last 90 days</option>
                                        <option value="all">All time</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Specialty</label>
                                    <select
                                        v-model="filters.specialty"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        @change="fetchBatchData"
                                    >
                                        <option value="">All Specialties</option>
                                        <option v-for="specialty in specialties" :key="specialty" :value="specialty">
                                            {{ specialty }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Min. Priority</label>
                                    <select
                                        v-model="filters.minPriority"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        @change="fetchBatchData"
                                    >
                                        <option value="0">Any Priority</option>
                                        <option value="1">Priority 1+</option>
                                        <option value="2">Priority 2+</option>
                                        <option value="3">Priority 3+</option>
                                        <option value="4">Priority 4+</option>
                                        <option value="5">Priority 5 only</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Optimization Metrics -->
                        <div class="mb-8">
                            <h3 class="text-xl font-semibold mb-4">Optimization Metrics</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Day Factor Optimization Chart -->
                                <div class="bg-white p-4 rounded-lg shadow border border-gray-200">
                                    <h4 class="text-lg font-medium mb-3">Time of Month Distribution</h4>
                                    <div class="h-64 relative">
                                        <canvas id="dayFactorChart"></canvas>
                                    </div>
                                    <p class="text-sm text-gray-600 mt-3">
                                        This chart shows how claims are distributed across the month to minimize time-based processing costs.
                                    </p>
                                </div>

                                <!-- Specialty Cost Optimization Chart -->
                                <div class="bg-white p-4 rounded-lg shadow border border-gray-200">
                                    <h4 class="text-lg font-medium mb-3">Specialty Cost Distribution</h4>
                                    <div class="h-64 relative">
                                        <canvas id="specialtyChart"></canvas>
                                    </div>
                                    <p class="text-sm text-gray-600 mt-3">
                                        This chart shows how different specialties contribute to total processing costs.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Batches -->
                        <div>
                            <h3 class="text-xl font-semibold mb-4">Recent Batches</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-300">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Batch ID</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Insurer</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Date</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Claims</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Total Value</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Processing Cost</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Est. Savings</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white">
                                        <tr v-for="batch in recentBatches" :key="batch.id" class="hover:bg-gray-50">
                                            <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-blue-600">{{ batch.batch_id }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{{ batch.insurer_name }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{{ formatDate(batch.batch_date) }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900">{{ batch.claim_count }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900">${{ batch.total_value.toFixed(2) }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900">${{ batch.processing_cost.toFixed(2) }}</td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900 text-green-600">${{ batch.estimated_savings.toFixed(2) }}</td>
                                        </tr>
                                        <tr v-if="recentBatches.length === 0">
                                            <td colspan="7" class="py-4 text-center text-sm text-gray-500">No batches found with the current filters</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-4 flex justify-center">
                                <button
                                    v-if="recentBatches.length > 0 && hasMoreBatches"
                                    @click="loadMoreBatches"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                                >
                                    Load More
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { Chart, registerables } from 'chart.js';
Chart.register(...registerables);

// State
const summary = ref({
    totalBatches: 0,
    totalClaims: 0,
    avgClaimsPerBatch: 0,
    costSavings: 0,
    savingsPercentage: 0,
    insurerCount: 0
});

const specialties = ref([
    'Cardiology',
    'Dermatology',
    'Endocrinology',
    'Gastroenterology',
    'Neurology',
    'Obstetrics',
    'Oncology',
    'Ophthalmology',
    'Orthopedics',
    'Pediatrics',
    'Psychiatry',
    'Urology'
]);

const insurers = ref([]);
const recentBatches = ref([]);
const hasMoreBatches = ref(true);
const currentPage = ref(1);
const filters = ref({
    insurer: '',
    dateRange: '30',
    specialty: '',
    minPriority: '0'
});

// Chart instances
let dayFactorChart = null;
let specialtyChart = null;
let batchDistributionChart = null;
let costSavingsChart = null;

// Lifecycle hooks
onMounted(async () => {
    await loadInsurersList();
    await fetchBatchData();
});

// Methods
async function loadInsurersList() {
    try {
        const response = await axios.get('/api/claims/insurers');
        insurers.value = response.data;
    } catch (error) {
        console.error('Error loading insurers:', error);
    }
}

async function fetchBatchData() {
    try {
        // Reset pagination when filters change
        currentPage.value = 1;
        recentBatches.value = [];
        hasMoreBatches.value = true;

        // Fetch summary data
        const summaryResponse = await axios.get('/api/batch-dashboard/summary', {
            params: {
                insurer_id: filters.value.insurer,
                date_range: filters.value.dateRange,
                specialty: filters.value.specialty,
                min_priority: filters.value.minPriority
            }
        });
        summary.value = summaryResponse.data;

        // Load charts data
        await loadChartData();

        // Load first page of batches
        await loadMoreBatches();
    } catch (error) {
        console.error('Error fetching batch data:', error);
    }
}

async function loadMoreBatches() {
    try {
        const response = await axios.get('/api/batch-dashboard/batches', {
            params: {
                page: currentPage.value,
                insurer_id: filters.value.insurer,
                date_range: filters.value.dateRange,
                specialty: filters.value.specialty,
                min_priority: filters.value.minPriority
            }
        });

        const newBatches = response.data.data;
        recentBatches.value = [...recentBatches.value, ...newBatches];

        // Check if there are more batches to load
        if (newBatches.length < response.data.per_page || currentPage.value >= response.data.last_page) {
            hasMoreBatches.value = false;
        } else {
            currentPage.value++;
        }
    } catch (error) {
        console.error('Error loading more batches:', error);
    }
}

async function loadChartData() {
    try {
        const chartDataResponse = await axios.get('/api/batch-dashboard/chart-data', {
            params: {
                insurer_id: filters.value.insurer,
                date_range: filters.value.dateRange,
                specialty: filters.value.specialty,
                min_priority: filters.value.minPriority
            }
        });

        const chartData = chartDataResponse.data;

        renderDayFactorChart(chartData.dayFactorData);
        renderSpecialtyChart(chartData.specialtyData);
    } catch (error) {
        console.error('Error loading chart data:', error);
    }
}

function renderDayFactorChart(data) {
    const ctx = document.getElementById('dayFactorChart');

    // Destroy previous chart instance if it exists
    if (dayFactorChart) {
        dayFactorChart.destroy();
    }

    dayFactorChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Claim Count',
                data: data.claimCounts,
                backgroundColor: 'rgba(59, 130, 246, 0.5)',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 1
            }, {
                label: 'Cost Factor (%)',
                data: data.costFactors,
                type: 'line',
                yAxisID: 'y1',
                backgroundColor: 'rgba(239, 68, 68, 0.2)',
                borderColor: 'rgb(239, 68, 68)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Claim Count'
                    }
                },
                y1: {
                    position: 'right',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Cost Factor (%)'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });
}

function renderSpecialtyChart(data) {
    const ctx = document.getElementById('specialtyChart');

    // Destroy previous chart instance if it exists
    if (specialtyChart) {
        specialtyChart.destroy();
    }

    specialtyChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Processing Cost',
                data: data.costs,
                backgroundColor: [
                    'rgba(59, 130, 246, 0.7)',
                    'rgba(16, 185, 129, 0.7)',
                    'rgba(239, 68, 68, 0.7)',
                    'rgba(245, 158, 11, 0.7)',
                    'rgba(139, 92, 246, 0.7)',
                    'rgba(236, 72, 153, 0.7)',
                    'rgba(6, 182, 212, 0.7)',
                    'rgba(249, 115, 22, 0.7)',
                    'rgba(37, 99, 235, 0.7)',
                    'rgba(5, 150, 105, 0.7)',
                    'rgba(220, 38, 38, 0.7)',
                    'rgba(217, 119, 6, 0.7)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'right',
                }
            }
        }
    });
}

function formatDate(dateString) {
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return new Date(dateString).toLocaleDateString(undefined, options);
}
</script>