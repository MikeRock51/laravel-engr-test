<template>
    <AuthenticatedLayout>
        <Head title="Claim Batches" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="card-header flex justify-between items-center mb-4">
                            <div class="text-xl font-semibold">Claim Batches</div>
                            <button
                                @click="processBatches"
                                class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline"
                                :disabled="processing"
                            >
                                {{ processing ? 'Processing...' : 'Process Pending Claims' }}
                            </button>
                        </div>

                        <div class="card-body">
                            <!-- Filters -->
                            <div class="mb-6 p-4 bg-gray-50 rounded">
                                <h3 class="text-lg font-semibold mb-3">Filters</h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-gray-700 text-sm font-bold mb-2">
                                            Insurer
                                        </label>
                                        <select
                                            v-model="filters.insurer_id"
                                            class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                        >
                                            <option :value="null">All Insurers</option>
                                            <option v-for="insurer in insurers" :key="insurer.id" :value="insurer.id">
                                                {{ insurer.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-gray-700 text-sm font-bold mb-2">
                                            From Date
                                        </label>
                                        <input
                                            v-model="filters.from_date"
                                            type="date"
                                            class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                        >
                                    </div>
                                    <div>
                                        <label class="block text-gray-700 text-sm font-bold mb-2">
                                            To Date
                                        </label>
                                        <input
                                            v-model="filters.to_date"
                                            type="date"
                                            class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                        >
                                    </div>
                                </div>
                                <div class="mt-4 flex justify-end">
                                    <button
                                        @click="applyFilters"
                                        class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline"
                                    >
                                        Apply Filters
                                    </button>
                                </div>
                            </div>

                            <!-- Batches Summary -->
                            <div v-if="batches.length > 0">
                                <h3 class="text-lg font-semibold mb-3">Batches</h3>

                                <div class="overflow-x-auto">
                                    <table class="min-w-full bg-white">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="px-6 py-3 border-b border-gray-200 text-left text-xs leading-4 font-medium text-gray-500 uppercase tracking-wider">
                                                    Batch ID
                                                </th>
                                                <th class="px-6 py-3 border-b border-gray-200 text-left text-xs leading-4 font-medium text-gray-500 uppercase tracking-wider">
                                                    Insurer
                                                </th>
                                                <th class="px-6 py-3 border-b border-gray-200 text-left text-xs leading-4 font-medium text-gray-500 uppercase tracking-wider">
                                                    Date
                                                </th>
                                                <th class="px-6 py-3 border-b border-gray-200 text-left text-xs leading-4 font-medium text-gray-500 uppercase tracking-wider">
                                                    Claims
                                                </th>
                                                <th class="px-6 py-3 border-b border-gray-200 text-left text-xs leading-4 font-medium text-gray-500 uppercase tracking-wider">
                                                    Total Value
                                                </th>
                                                <th class="px-6 py-3 border-b border-gray-200 text-left text-xs leading-4 font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="batch in batches" :key="batch.batch_id" class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-no-wrap border-b border-gray-200">
                                                    {{ batch.batch_id }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-no-wrap border-b border-gray-200">
                                                    {{ batch.insurer.name }} ({{ batch.insurer.code }})
                                                </td>
                                                <td class="px-6 py-4 whitespace-no-wrap border-b border-gray-200">
                                                    {{ formatDate(batch.batch_date) }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-no-wrap border-b border-gray-200">
                                                    {{ batch.claim_count }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-no-wrap border-b border-gray-200">
                                                    ${{ formatCurrency(batch.total_value) }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-no-wrap border-b border-gray-200">
                                                    <button
                                                        @click="viewBatchDetails(batch.batch_id)"
                                                        class="text-blue-600 hover:text-blue-900"
                                                    >
                                                        View Details
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div v-else class="text-center py-8">
                                <p class="text-gray-500">No batches found. Process pending claims to create batches.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Batch Processing Results Modal -->
        <div v-if="showResultsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-lg max-w-2xl w-full">
                <h3 class="text-lg font-semibold text-blue-600 mb-4">Batch Processing Results</h3>

                <div v-if="batchResults && Object.keys(batchResults).length > 0">
                    <div v-for="(insurerBatches, insurerCode) in batchResults" :key="insurerCode" class="mb-4">
                        <h4 class="font-medium text-gray-700">{{ insurerCode }}</h4>
                        <ul class="list-disc pl-5 mt-2">
                            <li v-for="(batch, index) in insurerBatches" :key="index" class="mb-2">
                                Batch {{ batch.batch_id }}: {{ batch.claim_count }} claims,
                                Total value: ${{ formatCurrency(batch.total_value) }},
                                Processing cost: ${{ formatCurrency(batch.processing_cost) }}
                            </li>
                        </ul>
                    </div>
                </div>
                <div v-else>
                    <p>No new batches were created. There may be no pending claims that meet the criteria.</p>
                </div>

                <div class="mt-6 flex justify-end">
                    <button
                        @click="closeResultsModal"
                        class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>

        <!-- Batch Details Modal -->
        <div v-if="showDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-lg max-w-4xl w-full max-h-screen overflow-y-auto">
                <h3 class="text-lg font-semibold text-blue-600 mb-4">Batch Details: {{ selectedBatchId }}</h3>

                <div v-if="batchClaims.length > 0">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-2 border-b border-gray-200 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Claim ID
                                </th>
                                <th class="px-4 py-2 border-b border-gray-200 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Provider
                                </th>
                                <th class="px-4 py-2 border-b border-gray-200 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Specialty
                                </th>
                                <th class="px-4 py-2 border-b border-gray-200 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Priority
                                </th>
                                <th class="px-4 py-2 border-b border-gray-200 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Encounter Date
                                </th>
                                <th class="px-4 py-2 border-b border-gray-200 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="claim in batchClaims" :key="claim.id" class="hover:bg-gray-50">
                                <td class="px-4 py-2 whitespace-no-wrap border-b border-gray-200">
                                    {{ claim.id }}
                                </td>
                                <td class="px-4 py-2 whitespace-no-wrap border-b border-gray-200">
                                    {{ claim.provider_name }}
                                </td>
                                <td class="px-4 py-2 whitespace-no-wrap border-b border-gray-200">
                                    {{ claim.specialty }}
                                </td>
                                <td class="px-4 py-2 whitespace-no-wrap border-b border-gray-200">
                                    {{ claim.priority_level }}
                                </td>
                                <td class="px-4 py-2 whitespace-no-wrap border-b border-gray-200">
                                    {{ formatDate(claim.encounter_date) }}
                                </td>
                                <td class="px-4 py-2 whitespace-no-wrap border-b border-gray-200">
                                    ${{ formatCurrency(claim.total_amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex justify-end">
                    <button
                        @click="closeDetailsModal"
                        class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Head } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { ref, onMounted } from 'vue';
import axios from 'axios';

const insurers = ref([]);
const batches = ref([]);
const batchClaims = ref([]);
const processing = ref(false);
const showResultsModal = ref(false);
const showDetailsModal = ref(false);
const batchResults = ref(null);
const selectedBatchId = ref('');

const filters = ref({
    insurer_id: null,
    from_date: '',
    to_date: ''
});

onMounted(async () => {
    try {
        // Load insurers
        const insurersResponse = await axios.get('/api/claims/insurers');
        insurers.value = insurersResponse.data;

        // Load batches summary
        await loadBatches();
    } catch (error) {
        console.error('Error loading data:', error);
    }
});

async function loadBatches() {
    try {
        const response = await axios.get('/api/claims/batch-summary');
        batches.value = response.data;
    } catch (error) {
        console.error('Error loading batches:', error);
    }
}

async function processBatches() {
    processing.value = true;

    try {
        const response = await axios.post('/api/claims/process-batches');
        batchResults.value = response.data.batches;
        showResultsModal.value = true;

        // Reload batches after processing
        await loadBatches();
    } catch (error) {
        console.error('Error processing batches:', error);
        alert('Failed to process batches. Please try again.');
    } finally {
        processing.value = false;
    }
}

function closeResultsModal() {
    showResultsModal.value = false;
}

async function viewBatchDetails(batchId) {
    selectedBatchId.value = batchId;

    try {
        const response = await axios.get('/api/claims/list', {
            params: { batch_id: batchId }
        });

        batchClaims.value = response.data.data;
        showDetailsModal.value = true;
    } catch (error) {
        console.error('Error loading batch details:', error);
    }
}

function closeDetailsModal() {
    showDetailsModal.value = false;
}

async function applyFilters() {
    try {
        const params = {};

        if (filters.value.insurer_id) {
            params.insurer_id = filters.value.insurer_id;
        }

        if (filters.value.from_date && filters.value.to_date) {
            params.from_date = filters.value.from_date;
            params.to_date = filters.value.to_date;
        }

        const response = await axios.get('/api/claims/batch-summary', { params });
        batches.value = response.data;
    } catch (error) {
        console.error('Error applying filters:', error);
    }
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString();
}

function formatCurrency(value) {
    if (!value) return '0.00';
    return parseFloat(value).toFixed(2);
}
</script>