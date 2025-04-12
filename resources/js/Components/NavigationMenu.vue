<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    auth: {
        type: Object,
        default: () => ({})
    }
});

const isLoggedIn = computed(() => props.auth.user !== null);
</script>

<template>
    <nav class="bg-white border-b border-gray-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Left side navigation items -->
                <div class="flex">
                    <div class="flex space-x-8 items-center">
                        <template v-if="isLoggedIn">
                            <Link
                                :href="route('submit-claim')"
                                class="px-3 py-2 rounded-md text-sm font-medium text-gray-900 hover:text-gray-700 hover:bg-gray-50"
                                :class="{ 'bg-gray-100': route().current('submit-claim') }"
                            >
                                Submit Claim
                            </Link>
                            <Link
                                :href="route('batches')"
                                class="px-3 py-2 rounded-md text-sm font-medium text-gray-900 hover:text-gray-700 hover:bg-gray-50"
                                :class="{ 'bg-gray-100': route().current('batches') }"
                            >
                                Claim Batches
                            </Link>
                        </template>
                    </div>
                </div>

                <!-- Right side navigation items -->
                <div class="flex items-center">
                    <template v-if="isLoggedIn">
                        <Link
                            :href="route('dashboard')"
                            class="px-3 py-2 rounded-md text-sm font-medium text-gray-900 hover:text-gray-700 hover:bg-gray-50"
                            :class="{ 'bg-gray-100': route().current('dashboard') }"
                        >
                            Dashboard
                        </Link>
                        <Link
                            :href="route('profile.edit')"
                            class="px-3 py-2 ml-3 rounded-md text-sm font-medium text-gray-900 hover:text-gray-700 hover:bg-gray-50"
                            :class="{ 'bg-gray-100': route().current('profile.edit') }"
                        >
                            Profile
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="px-3 py-2 ml-3 rounded-md text-sm font-medium text-gray-900 hover:text-gray-700 hover:bg-gray-50"
                        >
                            Logout
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="px-3 py-2 rounded-md text-sm font-medium text-gray-900 hover:text-gray-700 hover:bg-gray-50"
                        >
                            Login
                        </Link>
                        <Link
                            :href="route('register')"
                            class="ml-3 px-3 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700"
                        >
                            Register
                        </Link>
                    </template>
                </div>
            </div>
        </div>
    </nav>
</template>