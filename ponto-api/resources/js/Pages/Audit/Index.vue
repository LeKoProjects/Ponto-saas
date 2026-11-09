<script setup>
import { Head } from '@inertiajs/vue3';
import Pagination from '../../Components/Pagination.vue';

defineProps({ logs: Object });
const show = (v) => (v ? JSON.stringify(v, null, 1) : '');
</script>

<template>
    <Head title="Auditoria" />
    <h1 class="h3 mb-3">Trilha de auditoria</h1>
    <p class="text-muted small">Registros somente de inserção — não podem ser alterados nem excluídos.</p>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>Antes</th><th>Depois</th><th>IP</th></tr></thead>
                <tbody>
                    <tr v-for="l in logs.data" :key="l.id">
                        <td class="text-nowrap">{{ l.created_at }}</td>
                        <td>{{ l.user }}</td>
                        <td><code>{{ l.action }}</code></td>
                        <td>{{ l.entity }}</td>
                        <td><pre class="small mb-0" style="max-width: 260px; white-space: pre-wrap">{{ show(l.before) }}</pre></td>
                        <td><pre class="small mb-0" style="max-width: 260px; white-space: pre-wrap">{{ show(l.after) }}</pre></td>
                        <td class="small">{{ l.ip }}</td>
                    </tr>
                    <tr v-if="!logs.data.length"><td colspan="7" class="text-center text-muted py-4">Nenhum registro.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <Pagination :paginator="logs" />
</template>
