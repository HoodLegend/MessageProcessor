<script setup>
import { useCurrentTime } from '@/Composables/useCurrentTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const { currentTime } = useCurrentTime();
const page = usePage()
const userName = computed(() => page.props.auth.user.name)


const greeting = computed(() => {
    const hour = currentTime.value.getHours();
    let timeGreeting = ''

    if (hour >= 5 && hour < 12) {
        timeGreeting = 'Good Morning 🌄'
    } else if (hour >= 12 && hour < 17) {
        timeGreeting = 'Good Afternoon 🕛'
    } else if (hour >= 17 && hour < 21) {
        timeGreeting = 'Good Evening 🌆'
    } else {
        timeGreeting = 'Good Night'
    }

    return `${timeGreeting}, ${userName.value}`
})
</script>

<template>

    <Head title="Dashboard" />

    <AuthenticatedLayout>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        {{ greeting }}
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
