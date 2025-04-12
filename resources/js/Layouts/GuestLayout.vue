<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import NavigationMenu from '@/Components/NavigationMenu.vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <div class="min-h-screen flex flex-col bg-gray-100">
        <!-- Navigation Menu -->
        <NavigationMenu :auth="page.props.auth" />

        <div class="flex flex-col sm:justify-center items-center pt-6 sm:pt-0 flex-grow">
            <div>
                <Link href="/">
                    <ApplicationLogo class="w-20 h-20 fill-current text-gray-500" />
                </Link>
            </div>

            <div
                class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg"
            >
                <slot />
            </div>
        </div>
    </div>
</template>
