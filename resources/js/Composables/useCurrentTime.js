import {onBeforeUnmount, ref } from "vue";

export const useCurrentTime = () => {
    // variable that stores the current Time.
    const currentTime = ref(new Date());

    // updates the current Time on the timer
    const updateCurrentTime = () => {
        currentTime.value = new Date();
    };

    // updates the time based on the time interval set in this case every one second..
    const updateTimeInterval = setInterval(updateCurrentTime, 1000);

    onBeforeUnmount(() => {
        clearInterval(updateTimeInterval);
    });

    return {
        currentTime,
    };
};
