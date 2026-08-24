<template>
    <dialog class="modal"><slot></slot></dialog>
</template>

<script>
    export default { name: 'Modal' };

    export const Backdrop = { name: 'Backdrop', template: '<div class="backdrop"></div>' };
</script>
