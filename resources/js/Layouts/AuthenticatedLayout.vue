<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';
import DigitalClock from '@/Pages/DigitalClock.vue';

const showingNavigationDropdown = ref(false);
const showingSidebar = ref(false)
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <!-- Mobile sidebar overlay -->
            <div
                v-show="showingSidebar"
                class="fixed inset-0 z-40 flex lg:hidden"
                @click="showingSidebar = false"
            >
                <div class="fixed inset-0 bg-gray-600 bg-opacity-75"></div>
                <div class="relative flex w-full max-w-xs flex-1 flex-col bg-white">
                    <div class="absolute right-0 top-0 -mr-12 pt-2">
                        <button
                            @click="showingSidebar = false"
                            class="ml-1 flex h-10 w-10 items-center justify-center rounded-full focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white"
                        >
                            <svg class="h-6 w-6 text-white" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <!-- Mobile sidebar content -->
                    <!-- Mobile sidebar content -->
                    <div class="flex h-full flex-col overflow-y-auto pb-4 pt-5">
                        <div class="flex shrink-0 items-center px-4">
                            <ApplicationLogo class="h-8 w-auto" />
                        </div>
                        <nav class="mt-8 flex-1 px-2">
                            <div class="flex flex-col space-y-1">
                                <NavLink
                                    :href="route('transactions.index')"
                                    :active="route().current('transactions.index')"
                                    class="group flex items-center rounded-md px-2 py-3 text-base font-medium"
                                >
                                    <svg class="mr-4 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                    </svg>
                                    Messages
                                </NavLink>
                            </div>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Desktop sidebar -->
            <div class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
                <div class="flex flex-1 flex-col overflow-y-auto border-r border-gray-200 bg-white pb-4 pt-5">
                    <div class="flex shrink-0 items-center px-4">
                        <Link :href="route('dashboard')">
                            <ApplicationLogo class="h-8 w-auto" />
                        </Link>
                    </div>
                    <nav class="mt-8 flex-1 space-y-1 px-2">
                        <NavLink
                            :href="route('transactions.index')"
                            :active="route().current('transactions.index')"
                            class="group flex items-center rounded-md px-2 py-2 text-sm font-medium"
                        >
                            <svg class="mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            Messages
                        </NavLink>
                    </nav>
                </div>
            </div>

            <!-- Main content area -->
            <div class="flex flex-1 flex-col lg:pl-64">
                <!-- Top navbar -->
                <nav class="border-b border-gray-200 bg-white shadow-sm">
                    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div class="flex h-16 justify-between">
                            <div class="flex">
                                <!-- Mobile menu button -->
                                <div class="flex items-center lg:hidden">
                                    <button
                                        @click="showingSidebar = !showingSidebar"
                                        class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500"
                                    >
                                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                        </svg>
                                    </button>
                                </div>

                                <!-- Logo for mobile -->
                                <div class="flex shrink-0 items-center lg:hidden ml-4">
                                    <Link :href="route('dashboard')">
                                        <ApplicationLogo class="block h-8 w-auto fill-current text-gray-800" />
                                    </Link>
                                </div>
                            </div>

                            <!-- Right side of navbar -->
                            <div class="flex items-center">
                                <DigitalClock />
                                <!-- Settings Dropdown -->
                                <div class="relative ml-3">

                                    <Dropdown align="right" width="48">
                                        <template #trigger>
                                            <span class="inline-flex rounded-md">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                                >
                                                    {{ $page.props.auth.user.name }}

                                                    <svg
                                                        class="-me-0.5 ms-2 h-4 w-4"
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                    >
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                            clip-rule="evenodd"
                                                        />
                                                    </svg>
                                                </button>
                                            </span>
                                        </template>

                                        <template #content>
                                            <DropdownLink :href="route('profile.edit')">
                                                Profile
                                            </DropdownLink>
                                            <DropdownLink
                                                :href="route('logout')"
                                                method="post"
                                                as="button"
                                            >
                                                Log Out
                                            </DropdownLink>
                                        </template>
                                    </Dropdown>
                                </div>
                            </div>
                        </div>
                    </div>
                </nav>

                <!-- Page Heading -->
                <header
                    class="bg-white shadow"
                    v-if="$slots.header"
                >
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        <slot name="header" />
                    </div>
                </header>

                <!-- Page Content -->
                <main class="flex-1">
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>
