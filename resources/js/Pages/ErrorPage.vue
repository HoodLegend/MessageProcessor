<template>
<div class="flex flex-col min-h-screen justify-center items-center bg-[#578FCA] px-4 text-center">
    <h2 class="font-sans font-bold text-[#333446] text-3xl sm:text-4xl md:text-5xl lg:text-6xl">
        {{ error }} 😒
    </h2>
    <div class="font-sans text-[#1a1b21] mt-4 text-xl sm:text-2xl md:text-3xl lg:text-4xl">
        Oops! {{ message }}
    </div>
</div>

</template>


<script setup>
import { ref } from 'vue';


const props = defineProps({
    error:String,
    message:String
});

const error = ref('');
const message = ref('');

error.value = props?.error;
message.value = props?.message;



</script>
