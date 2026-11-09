<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Pagination from '../../Components/Pagination.vue';

defineProps({ adjustments: Object });
</script>

<template>
    <Head title="Tratamentos" />
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Tratamentos de ponto</h1>
        <Link href="/espelho" class="btn btn-outline-primary">Lançar pelo espelho</Link>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Lançado em</th><th>Funcionário</th><th>Dia</th><th>Tipo</th><th>Detalhe</th><th>Justificativa</th><th>Status</th><th>Por</th></tr></thead>
                <tbody>
                    <tr v-for="a in adjustments.data" :key="a.id">
                        <td class="text-nowrap">{{ a.created_at }}</td>
                        <td>{{ a.employee }}</td>
                        <td>{{ a.date }}</td>
                        <td>{{ a.type }}</td>
                        <td>{{ a.detail }}</td>
                        <td class="small">{{ a.reason }}</td>
                        <td><span class="badge text-bg-success">{{ a.status }}</span></td>
                        <td>{{ a.approved_by ?? a.requested_by }}</td>
                    </tr>
                    <tr v-if="!adjustments.data.length"><td colspan="8" class="text-center text-muted py-4">Nenhum tratamento.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <Pagination :paginator="adjustments" />
</template>
