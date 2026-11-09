<script setup>
defineProps({ status: String, label: String });

const cls = { pendente: 'warning', aprovado: 'success', recusado: 'secondary', revogado: 'danger' };
const icon = { pendente: 'hourglass-split', aprovado: 'check-circle-fill', recusado: 'x-circle', revogado: 'slash-circle' };
</script>

<template>
    <span :class="`badge text-bg-${cls[status]} d-inline-flex align-items-center gap-1`">
        <i :class="`bi bi-${icon[status]}`"></i>{{ label }}
    </span>
</template>
