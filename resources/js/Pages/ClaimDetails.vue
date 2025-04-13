<template>
    <AuthenticatedLayout>
        <Head :title="`Claim #${claim.id} Details`" />

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-semibold">Claim #{{ claim.id }} Details</h2>
                            <div>
                                <Link 
                                    :href="route('dashboard')" 
                                    class="text-blue-500 hover:text-blue-700 mr-4"
                                >
                                    Back to Dashboard
                                </Link>
                                <div class="inline-block px-3 py-1 rounded-full text-sm font-semibold" 
                                    :class="getStatusClass(claim.status)">
                                    {{ claim.status.charAt(0).toUpperCase() + claim.status.slice(1) }}
                                </div>
                            </div>
                        </div>

                        <!-- Basic Claim Information -->
                        <div class="mb-8 bg-gray-50 rounded-lg p-6">
                            <h3 class="text-lg font-medium border-b pb-2 mb-4">Claim Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <div>
                                    <p class="text-gray-500 text-sm">Insurer</p>
                                    <p class="font-medium">{{ claim.insurer.name }} ({{ claim.insurer.code }})</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Provider</p>
                                    <p class="font-medium">{{ claim.provider_name }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Specialty</p>
                                    <p class="font-medium">{{ claim.specialty }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Encounter Date</p>
                                    <p class="font-medium">{{ formatDate(claim.encounter_date) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Submission Date</p>
                                    <p class="font-medium">{{ formatDate(claim.submission_date) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Priority Level</p>
                                    <div class="flex items-center">
                                        <span class="font-medium mr-2">{{ claim.priority_level }}</span>
                                        <div class="flex">
                                            <div v-for="n in 5" :key="n" 
                                                class="w-4 h-4 rounded-full mx-0.5"
                                                :class="n <= claim.priority_level ? 'bg-blue-500' : 'bg-gray-200'">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Total Amount</p>
                                    <p class="font-medium text-lg">${{ formatNumber(claim.total_amount) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Created At</p>
                                    <p class="font-medium">{{ formatDateTime(claim.created_at) }}</p>
                                </div>
                                <div v-if="claim.batch_id">
                                    <p class="text-gray-500 text-sm">Batch ID</p>
                                    <p class="font-medium">{{ claim.batch_id }}</p>
                                </div>
                                <div v-if="claim.batch_date">
                                    <p class="text-gray-500 text-sm">Batch Date</p>
                                    <p class="font-medium">{{ formatDate(claim.batch_date) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Processing Cost Estimation -->
                        <div class="mb-8 bg-blue-50 rounded-lg p-6">
                            <h3 class="text-lg font-medium border-b pb-2 mb-4 border-blue-200">Processing Cost Estimation</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <p class="text-gray-600 text-sm">Base cost for {{ claim.specialty }}:</p>
                                    <p class="font-medium">${{ formatNumber(costFactors.baseCost) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-600 text-sm">Priority multiplier (Level {{ claim.priority_level }}):</p>
                                    <p class="font-medium">{{ formatNumber(costFactors.priorityMultiplier) }}x</p>
                                </div>
                                <div>
                                    <p class="text-gray-600 text-sm">Time of month factor:</p>
                                    <p class="font-medium">{{ formatNumber(costFactors.dayFactor) }}x</p>
                                    <p class="text-xs text-gray-500">
                                        Based on {{ costFactors.datePreference === 'encounter_date' ? 'Encounter' : 'Submission' }} Date
                                    </p>
                                </div>
                                <div>
                                    <p class="text-gray-600 text-sm">Value multiplier:</p>
                                    <p class="font-medium">{{ formatNumber(costFactors.valueMultiplier) }}x</p>
                                </div>
                            </div>
                            <div class="mt-4 pt-3 border-t border-blue-200">
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-600">Estimated processing cost:</span>
                                    <span class="text-xl font-bold text-blue-700">${{ formatNumber(estimatedCost) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Claim Items -->
                        <div class="mb-8">
                            <h3 class="text-lg font-medium mb-4">Claim Items</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full bg-white border border-gray-200">
                                    <thead>
                                        <tr>
                                            <th class="py-3 px-4 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase border-b">Item</th>
                                            <th class="py-3 px-4 bg-gray-100 text-right text-xs font-semibold text-gray-600 uppercase border-b">Unit Price</th>
                                            <th class="py-3 px-4 bg-gray-100 text-right text-xs font-semibold text-gray-600 uppercase border-b">Quantity</th>
                                            <th class="py-3 px-4 bg-gray-100 text-right text-xs font-semibold text-gray-600 uppercase border-b">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(item, index) in claim.items" :key="index" class="border-b">
                                            <td class="py-4 px-4">{{ item.name }}</td>
                                            <td class="py-4 px-4 text-right">${{ formatNumber(item.unit_price) }}</td>
                                            <td class="py-4 px-4 text-right">{{ item.quantity }}</td>
                                            <td class="py-4 px-4 text-right">${{ formatNumber(item.unit_price * item.quantity) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" class="py-4 px-4 text-right font-semibold">Total:</td>
                                            <td class="py-4 px-4 text-right font-bold">${{ formatNumber(claim.total_amount) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <div class="mt-8 flex justify-end">
                            <Link 
                                :href="route('dashboard')" 
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline"
                            >
                                Back to Dashboard
                            </Link>
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

// Props from the controller
const props = defineProps({
    claim: Object,
    estimatedCost: Number,
    costFactors: Object
});

// Format a date as MM/DD/YYYY
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    });
};

// Format a date and time
const formatDateTime = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
};

// Format a number with 2 decimal places
const formatNumber = (number) => {
    return parseFloat(number).toFixed(2);
};

// Get the CSS class for a claim status
const getStatusClass = (status) => {
    switch (status.toLowerCase()) {
        case 'pending':
            return 'bg-yellow-100 text-yellow-800';
        case 'batched':
            return 'bg-green-100 text-green-800';
        case 'processed':
            return 'bg-blue-100 text-blue-800';
        case 'rejected':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};
</script>