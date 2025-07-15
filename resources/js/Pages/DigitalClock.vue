<template>
  <div class="flex md:justify-end">
    <div class="py-2 px-4 mb-1">
      <p class="font-bold text-gray-600 pt-2 text-md md:text-sm">{{ currentTime.toLocaleTimeString() }}</p>
    </div>
  </div>
</template>

<script>
import { useCurrentTime } from '@/Composables/useCurrentTime';


export default {
    name: "CurrentTimeExample",
    setup(){
        const { currentTime } = useCurrentTime();
        console.log(currentTime);
        return {currentTime };
    },
};
</script>
