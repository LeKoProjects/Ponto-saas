<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ paginator: { type: Object, required: true } });
</script>

<template>
    <div v-if="paginator.last_page > 1" class="d-flex justify-content-between align-items-center mt-3">
        <small class="text-muted">{{ paginator.from }}–{{ paginator.to }} de {{ paginator.total }}</small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li v-for="(link, i) in paginator.links" :key="i" class="page-item" :class="{ active: link.active, disabled: !link.url }">
                    <Link v-if="link.url" class="page-link" :href="link.url" preserve-scroll v-html="link.label" />
                    <span v-else class="page-link" v-html="link.label" />
                </li>
            </ul>
        </nav>
    </div>
</template>
