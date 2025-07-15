<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});

const isOpen = ref(false);
const toggleMenu = () => {
    isOpen.value = !isOpen.value;
};

const page = usePage();
const isAuthenticated = computed(() => page.props.auth?.user !== null);

</script>

<template>

    <Head title="Welcome" />
    <header>
        <nav>
            <div class="container mx-auto px-6 py-2 flex justify-between items-center">
                <a class="font-bold text-xl lg:text-2xl text-gray-600" href="#">
                    Zambezi Solutions
                </a>

                <button @click="toggleMenu"
                    class="lg:hidden flex items-center px-3 py-2 border rounded text-gray-500 border-gray-600  hover:text-gray-800 hover:border-teal-500 appearance-none focus:outline-none">
                    <svg class="fill-current h-3 w-3" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <title>Menu</title>
                        <path d="M0 3h20v2H0V3zm0 6h20v2H0V9zm0 6h20v2H0v-2z" />
                    </svg>
                </button>


                <div class="hidden lg:flex space-x-4">
                    <template v-if="canLogin">
                        <Link v-if="isAuthenticated" :href="route('dashboard')"
                            class="text-gray-800 hover:text-blue-600 font-semibold">Dashboard</Link>
                        <template v-else>
                            <Link :href="route('login')" class="text-gray-800 hover:text-blue-600 font-semibold">Sign In
                            </Link>
                            <Link v-if="canRegister" :href="route('register')"
                                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Sign Up</Link>
                        </template>
                    </template>
                </div>
            </div>

            <!-- Mobile Slide Menu -->
            <transition name="slide">
                <div v-if="isOpen" class="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-40" @click="toggleMenu">
                    <div class="fixed top-0 left-0 w-2/4 h-full bg-white shadow-md z-50 p-6" @click.stop>
                        <div class="flex justify-between">
                            <a href="#" class="font-bold text-md">Zambezi</a>
                            <button class="mb-4 text-gray-700 hover:text-red-600" @click="toggleMenu">
                                <img src="../../assets/Images/cancel.svg" alt="cancel" class="w-5 h-5"/>
                            </button>
                        </div>


                        <nav class="flex flex-col space-y-4">
                            <template v-if="canLogin">
                                <Link v-if="isAuthenticated" :href="route('dashboard')"
                                    class="text-gray-800 font-medium">Dashboard</Link>
                                <template v-else>
                                    <Link :href="route('login')"
                                        class="text-gray-200 font-bold bg-blue-500 rounded-md shadow-md p-2 text-sm">
                                    Sign In</Link>
                                    <Link v-if="canRegister" :href="route('register')"
                                        class="text-blue-600 font-medium text-sm">Sign Up</Link>
                                </template>
                            </template>
                        </nav>
                    </div>
                </div>
            </transition>

        </nav>

        <div class="py-20" style="background: linear-gradient(90deg, #2F5249 0%, #0D5EA6 100%)">
            <div class="container mx-auto px-6">
                <h2 class="text-2xl font-bold mb-2 text-white">
                    Zambezi Solutions
                </h2>
                <h3 class="text-xl mb-8 text-gray-200">
                    Monitoring Receipt Notifications and Processing Messages
                </h3>

                <button
                    class="bg-[#2F5249] text-white font-bold rounded-full py-4 px-8 shadow-lg uppercase tracking-wider">
                    Get Started
                </button>
            </div>
        </div>
        <section class="container mx-auto px-6 py-10">
            <h2 class="text-4xl font-bold text-center text-gray-800 mb-12">
                Features
            </h2>

            <div class="flex flex-wrap justify-between gap-8">
                <!-- Feature 1 -->
                <div class="flex flex-col items-center text-center w-full md:w-[30%]">
                    <img src="../../assets/Images/fetchmessages.svg" alt="Fetching" class="mb-4 w-24 h-24" />
                    <h4 class="text-2xl font-bold text-gray-800 mb-2">Fetching Message Receipts</h4>
                    <p class="text-gray-600">
                        Fetching messages from the ReceiptIt server so they can be sent to the remote accounting
                        software.
                    </p>
                </div>

                <!-- Feature 2 -->
                <div class="flex flex-col items-center text-center w-full md:w-[30%]">
                    <img src="../../assets/Images/messagereports.svg" alt="Reporting" class="mb-4 w-24 h-24" />
                    <h4 class="text-2xl font-bold text-gray-800 mb-2">Reporting</h4>
                    <p class="text-gray-600">
                        Generates reports with charts and bar graphs showing which messages were received and
                        successfully sent.
                    </p>
                </div>

                <!-- Feature 3 -->
                <div class="flex flex-col items-center text-center w-full md:w-[30%]">
                    <img src="../../assets/Images/logging.svg" alt="Logging" class="mb-4 w-24 h-24" />
                    <h4 class="text-2xl font-bold text-gray-800 mb-2">Logging</h4>
                    <p class="text-gray-600">
                        Logs all messages sent to the accounting software, those fetched from the server, and failed
                        transactions.
                    </p>
                </div>
            </div>
        </section>


    </header>

    <main class="text-gray-700 bg-white" style="font-family: Source Sans Pro, sans-serif">
        <!-- main content here -->
         <div class="flex m-4 p-2 justify-between">
            <img src="../../assets/Images/screenshot.png" alt="screenshot" class="w-[80px] h-1/2" />
             <img src="../../assets/Images/screenshot.png" alt="screenshot" class="w-[80px] h-1/2" />
              <img src="../../assets/Images/screenshot.png" alt="screenshot" class="w-[80px] h-1/2" />
         </div>

    </main>


    <footer class="bg-[#4682A9] shadow-sm">
        <div class="container mx-auto px-6 pt-10 pb-6">
            <div class="flex flex-wrap">
                <div class="w-full md:w-1/4 text-center text-gray-300 md:text-left">
                    <h5 class="uppercase mb-6 font-bold">Links</h5>
                    <ul class="mb-4">
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">FAQ</a>
                        </li>
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Help</a>
                        </li>
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Support</a>
                        </li>
                    </ul>
                </div>
                <div class="w-full md:w-1/4 text-center text-gray-300 md:text-left">
                    <h5 class="uppercase mb-6 font-bold">Legal</h5>
                    <ul class="mb-4">
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Terms</a>
                        </li>
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Privacy</a>
                        </li>
                    </ul>
                </div>
                <div class="w-full md:w-1/4 text-gray-300 text-center md:text-left">
                    <h5 class="uppercase mb-6 font-bold">Social</h5>
                    <ul class="mb-4">
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Facebook</a>
                        </li>
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Linkedin</a>
                        </li>
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Twitter</a>
                        </li>
                    </ul>
                </div>
                <div class="w-full md:w-1/4 text-gray-300 text-bold text-center md:text-left">
                    <h5 class="uppercase mb-6 font-bold">Zambezi Solutions</h5>
                    <ul class="mb-4">
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Official Blog</a>
                        </li>
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">About Us</a>
                        </li>
                        <li class="mt-2">
                            <a href="#" class="hover:underline text-gray-200 hover:text-gray-800">Contact</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

</template>
