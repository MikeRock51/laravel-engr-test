<template>
    <AuthenticatedLayout>
        <Head title="Submit Claim" />

        <div class="py-12 max-w-3xl mx-auto">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="card-header text-xl font-semibold mb-4">Submit A Claim</div>

                        <div class="card-body">
                            <form @submit.prevent="submitClaim">
                                <!-- Insurer Selection -->
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="insurer">
                                        Insurer
                                    </label>
                                    <select
                                        v-model="form.insurer_id"
                                        id="insurer"
                                        class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                        :class="{ 'border-red-500': form.errors.insurer_id }"
                                        @change="updateCostEstimation"
                                    >
                                        <option value="" disabled>Select an insurer</option>
                                        <option v-for="insurer in insurers" :key="insurer.id" :value="insurer.id">
                                            {{ insurer.name }} ({{ insurer.code }})
                                        </option>
                                    </select>
                                    <p v-if="form.errors.insurer_id" class="text-red-500 text-xs italic">{{ form.errors.insurer_id }}</p>
                                </div>

                                <!-- Provider Name -->
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="provider_name">
                                        Provider Name
                                    </label>
                                    <input
                                        v-model="form.provider_name"
                                        type="text"
                                        id="provider_name"
                                        class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                        :class="{ 'border-red-500': form.errors.provider_name }"
                                    >
                                    <p v-if="form.errors.provider_name" class="text-red-500 text-xs italic">{{ form.errors.provider_name }}</p>
                                </div>

                                <!-- Specialty -->
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="specialty">
                                        Specialty
                                    </label>
                                    <select
                                        v-model="form.specialty"
                                        id="specialty"
                                        class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                        :class="{ 'border-red-500': form.errors.specialty }"
                                        @change="updateCostEstimation"
                                    >
                                        <option value="" disabled>Select a specialty</option>
                                        <option v-for="specialty in specialties" :key="specialty" :value="specialty">
                                            {{ specialty }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.specialty" class="text-red-500 text-xs italic">{{ form.errors.specialty }}</p>
                                </div>

                                <!-- Date Fields -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <!-- Encounter Date -->
                                    <div>
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="encounter_date">
                                            Encounter Date
                                        </label>
                                        <input
                                            v-model="form.encounter_date"
                                            type="date"
                                            id="encounter_date"
                                            :max="maxDate"
                                            class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                            :class="{ 'border-red-500': form.errors.encounter_date }"
                                            @change="updateCostEstimation"
                                        >
                                        <p v-if="form.errors.encounter_date" class="text-red-500 text-xs italic">{{ form.errors.encounter_date }}</p>
                                    </div>

                                    <!-- Submission Date -->
                                    <div>
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="submission_date">
                                            Submission Date
                                        </label>
                                        <input
                                            readonly
                                            v-model="form.submission_date"
                                            type="date"
                                            id="submission_date"
                                            class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                            :class="{ 'border-red-500': form.errors.submission_date }"
                                        >
                                        <p v-if="form.errors.submission_date" class="text-red-500 text-xs italic">{{ form.errors.submission_date }}</p>
                                    </div>
                                </div>

                                <!-- Priority Level -->
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2">
                                        Priority Level (1-5, where 5 is highest)
                                    </label>
                                    <div class="flex space-x-4">
                                        <label v-for="n in 5" :key="n" class="inline-flex items-center">
                                            <input
                                                type="radio"
                                                :value="n"
                                                v-model="form.priority_level"
                                                class="form-radio h-5 w-5 text-blue-600"
                                                @change="updateCostEstimation"
                                            >
                                            <span class="ml-2 text-gray-700">{{ n }}</span>
                                        </label>
                                    </div>
                                    <p v-if="form.errors.priority_level" class="text-red-500 text-xs italic">{{ form.errors.priority_level }}</p>
                                </div>

                                <!-- Claim Items Section -->
                                <div class="mt-6 mb-4">
                                    <h3 class="text-lg font-semibold mb-2">Claim Items</h3>

                                    <div v-for="(item, index) in form.items" :key="index" class="p-4 border rounded mb-4 bg-gray-50">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <!-- Item Name -->
                                            <div>
                                                <label class="block text-gray-700 text-sm font-bold mb-2">
                                                    Item Name
                                                </label>
                                                <input
                                                    v-model="item.name"
                                                    type="text"
                                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                                    :class="{ 'border-red-500': form.errors['items.' + index + '.name'] }"
                                                >
                                                <p v-if="form.errors['items.' + index + '.name']" class="text-red-500 text-xs italic">
                                                    {{ form.errors['items.' + index + '.name'] }}
                                                </p>
                                            </div>

                                            <!-- Unit Price -->
                                            <div>
                                                <label class="block text-gray-700 text-sm font-bold mb-2">
                                                    Unit Price ($)
                                                </label>
                                                <input
                                                    v-model.number="item.unit_price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                                    :class="{ 'border-red-500': form.errors['items.' + index + '.unit_price'] }"
                                                    @change="updateCostEstimation"
                                                >
                                                <p v-if="form.errors['items.' + index + '.unit_price']" class="text-red-500 text-xs italic">
                                                    {{ form.errors['items.' + index + '.unit_price'] }}
                                                </p>
                                            </div>

                                            <!-- Quantity -->
                                            <div>
                                                <label class="block text-gray-700 text-sm font-bold mb-2">
                                                    Quantity
                                                </label>
                                                <input
                                                    v-model.number="item.quantity"
                                                    type="number"
                                                    min="1"
                                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                                    :class="{ 'border-red-500': form.errors['items.' + index + '.quantity'] }"
                                                    @change="updateCostEstimation"
                                                >
                                                <p v-if="form.errors['items.' + index + '.quantity']" class="text-red-500 text-xs italic">
                                                    {{ form.errors['items.' + index + '.quantity'] }}
                                                </p>
                                            </div>
                                        </div>

                                        <!-- Subtotal & Remove Button -->
                                        <div class="flex justify-between items-center mt-3">
                                            <div class="text-gray-700">
                                                <strong>Subtotal:</strong> ${{ calculateSubtotal(item) }}
                                            </div>
                                            <button
                                                type="button"
                                                @click="removeItem(index)"
                                                v-if="form.items.length > 1"
                                                class="bg-red-500 hover:bg-red-700 text-white font-bold py-1 px-3 rounded focus:outline-none focus:shadow-outline"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Add Item Button -->
                                    <button
                                        type="button"
                                        @click="addItem"
                                        class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline mt-2"
                                    >
                                        Add Another Item
                                    </button>

                                    <!-- Total Amount -->
                                    <div class="mt-4 text-right">
                                        <div class="text-lg font-bold">
                                            Total: ${{ calculateTotal() }}
                                        </div>
                                        <button
                                            type="button"
                                            @click="updateCostEstimation"
                                            class="mt-2 bg-indigo-100 hover:bg-indigo-200 text-indigo-800 py-1 px-4 rounded text-sm focus:outline-none focus:shadow-outline"
                                        >
                                            {{ showCostEstimation ? 'Update Cost Estimate' : 'Show Cost Estimate' }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Cost Estimation Panel - Moved to bottom of form before submit button -->
                                <div v-if="showCostEstimation && costEstimation" class="my-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                    <h3 class="text-lg font-semibold mb-2 text-blue-700">Processing Cost Estimation</h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Base cost for {{ form.specialty || 'selected specialty' }}:</p>
                                            <p class="font-medium">${{ costEstimation.baseCost?.toFixed(2) || '0.00' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Priority multiplier (Level {{ form.priority_level }}):</p>
                                            <p class="font-medium">{{ costEstimation.priorityMultiplier?.toFixed(2) || '1.00' }}x</p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Time of month factor:</p>
                                            <p class="font-medium">{{ costEstimation.dayFactor?.toFixed(2) || '0.00' }}x</p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Value multiplier:</p>
                                            <p class="font-medium">{{ costEstimation.valueMultiplier?.toFixed(2) || '1.00' }}x</p>
                                        </div>
                                    </div>
                                    <div class="mt-3 pt-3 border-t border-blue-200">
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600">Estimated total processing cost:</span>
                                            <span class="text-xl font-bold text-blue-700">${{ costEstimation.totalCost?.toFixed(2) || '0.00' }}</span>
                                        </div>
                                        <div class="mt-2 text-sm" v-if="costEstimation.batchingTips">
                                            <p class="font-semibold text-indigo-600">Batching Tips:</p>
                                            <ul class="list-disc pl-5 mt-1 text-gray-600">
                                                <li v-for="(tip, index) in costEstimation.batchingTips" :key="index">{{ tip }}</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <div class="flex items-center justify-between mt-6">
                                    <button
                                        type="submit"
                                        class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline"
                                        :disabled="processing"
                                    >
                                        {{ processing ? 'Submitting...' : 'Submit Claim' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success Message Modal -->
        <div v-if="showSuccessModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-lg max-w-md w-full">
                <h3 class="text-lg font-semibold text-green-600 mb-4">Claim Submitted Successfully!</h3>
                <p>Your claim has been received and is now pending processing.</p>
                <div class="mt-6 flex justify-end">
                    <button
                        @click="closeSuccessModal"
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
import { Head, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { ref, onMounted, computed, watch } from 'vue';
import axios from 'axios';

const insurers = ref([]);
const insurerDetails = ref({});
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

// Use Inertia form for submission
const form = useForm({
    insurer_id: '',
    provider_name: '',
    encounter_date: '',
    submission_date: new Date().toISOString().split('T')[0],
    priority_level: 3, // Default to medium priority
    specialty: '',
    items: [
        { name: '', unit_price: 0, quantity: 1 }
    ]
});

const processing = ref(false);
const showSuccessModal = ref(false);
const maxDate = new Date().toISOString().split('T')[0];
const costEstimation = ref(null);
const showCostEstimation = ref(false);

onMounted(async () => {
    try {
        const response = await axios.get('/api/claims/insurers');
        insurers.value = response.data;

        // Fetch detailed insurer information for cost estimation
        if (response.data.length > 0) {
            const detailsResponse = await axios.get('/api/claims/insurers/details');
            insurerDetails.value = detailsResponse.data;
        }
    } catch (error) {
        console.error('Error loading insurers:', error);
    }
});

function addItem() {
    form.items.push({ name: '', unit_price: 0, quantity: 1 });
}

function removeItem(index) {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
        updateCostEstimation();
    }
}

function calculateSubtotal(item) {
    return (parseFloat(item.unit_price) * parseInt(item.quantity)).toFixed(2);
}

function calculateTotal() {
    return form.items.reduce((total, item) => {
        return total + (parseFloat(item.unit_price) * parseInt(item.quantity));
    }, 0).toFixed(2);
}

async function updateCostEstimation() {
    if (!form.insurer_id || !form.specialty) {
        return;
    }

    showCostEstimation.value = true;

    try {
        const total = parseFloat(calculateTotal());
        const payload = {
            insurer_id: form.insurer_id,
            specialty: form.specialty,
            priority_level: form.priority_level,
            total_amount: total,
            encounter_date: form.encounter_date,
            submission_date: form.submission_date
        };

        // Use the globally configured axios instance that already has authentication
        // headers set up in bootstrap.js
        const response = await axios.post('/api/claims/estimate-cost', payload);
        costEstimation.value = response.data;
    } catch (error) {
        console.error('Error estimating cost:', error);
        costEstimation.value = null;
    }
}

// Watch for changes in key fields to auto-update cost estimation
watch([() => form.insurer_id, () => form.specialty, () => form.priority_level],
    (newValues, oldValues) => {
        if (newValues.every(val => val) &&
            (newValues[0] !== oldValues[0] || newValues[1] !== oldValues[1] || newValues[2] !== oldValues[2])) {
            updateCostEstimation();
        }
    }
);

// Form validation logic
function validateForm() {
    let isValid = true;
    const errors = {};

    // Validate required fields
    if (!form.insurer_id) {
        errors.insurer_id = "Please select an insurer";
        isValid = false;
    }

    if (!form.provider_name) {
        errors.provider_name = "Provider name is required";
        isValid = false;
    }

    if (!form.specialty) {
        errors.specialty = "Please select a specialty";
        isValid = false;
    }

    if (!form.encounter_date) {
        errors.encounter_date = "Encounter date is required";
        isValid = false;
    } else {
        // Validate that encounter date is not in the future
        const encounterDate = new Date(form.encounter_date);
        const today = new Date();
        if (encounterDate > today) {
            errors.encounter_date = "Encounter date cannot be in the future";
            isValid = false;
        }
    }

    if (!form.priority_level) {
        errors.priority_level = "Priority level is required";
        isValid = false;
    }

    // Validate claim items
    form.items.forEach((item, index) => {
        if (!item.name) {
            errors[`items.${index}.name`] = "Item name is required";
            isValid = false;
        }

        if (!item.unit_price || item.unit_price <= 0) {
            errors[`items.${index}.unit_price`] = "Unit price must be greater than 0";
            isValid = false;
        }

        if (!item.quantity || item.quantity < 1) {
            errors[`items.${index}.quantity`] = "Quantity must be at least 1";
            isValid = false;
        }
    });

    // Assign errors to form
    form.clearErrors();
    if (!isValid) {
        form.setError(errors);
    }

    return isValid;
}

function submitClaim() {
    // Validate form before submission
    if (!validateForm()) {
        // Show validation message
        const errorToast = document.createElement('div');
        errorToast.className = 'fixed top-4 right-4 bg-red-500 text-white px-4 py-2 rounded shadow-lg z-50';
        errorToast.innerHTML = 'Please fix the validation errors before submitting';
        document.body.appendChild(errorToast);

        // Remove after 3 seconds
        setTimeout(() => {
            document.body.removeChild(errorToast);
        }, 3000);

        return;
    }

    processing.value = true;

    // Use the web route for submitting claims
    form.post(route('claims.submit'), {
        preserveScroll: true,
        onSuccess: () => {
            // Reset form to default values
            form.reset();
            form.items = [{ name: '', unit_price: 0, quantity: 1 }];
            form.submission_date = new Date().toISOString().split('T')[0];
            form.priority_level = 3;

            // Show success modal
            showSuccessModal.value = true;
            processing.value = false;

            // Reset cost estimation
            costEstimation.value = null;
            showCostEstimation.value = false;
        },
        onError: (errors) => {
            console.error('Validation errors:', errors);
            processing.value = false;

            // Scroll to the first error
            setTimeout(() => {
                const firstErrorElement = document.querySelector('.border-red-500');
                if (firstErrorElement) {
                    firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstErrorElement.focus();
                }
            }, 100);
        },
        onFinish: () => {
            processing.value = false;
        }
    });
}

function closeSuccessModal() {
    showSuccessModal.value = false;
}
</script>
