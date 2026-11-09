<script setup>
import { onMounted, ref } from 'vue';
import { post } from '../api';

const month = ref(new Date().toISOString().slice(0, 7));
const report = ref(null);
const error = ref('');
const loading = ref(false);

const fmt = (m) => {
    if (!m) return '00:00';
    const s = m < 0 ? '-' : '';
    const a = Math.abs(m);
    return `${s}${String(Math.floor(a / 60)).padStart(2, '0')}:${String(a % 60).padStart(2, '0')}`;
};

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        report.value = await post('/historico', { month: month.value });
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};

onMounted(load);
</script>

<template>
    <div class="pt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Meu mês</h1>
            <input v-model="month" type="month" class="form-control w-auto" @change="load" />
        </div>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>

        <template v-else-if="report">
            <div class="row g-2 mb-3">
                <div class="col-4"><div class="card border-0 shadow-sm text-center py-2"><small class="text-muted">Trabalhado</small><b>{{ fmt(report.totals.worked_minutes) }}</b></div></div>
                <div class="col-4"><div class="card border-0 shadow-sm text-center py-2"><small class="text-muted">Extras</small><b class="text-success">{{ fmt(report.totals.overtime_50_minutes + report.totals.overtime_100_minutes) }}</b></div></div>
                <div class="col-4"><div class="card border-0 shadow-sm text-center py-2"><small class="text-muted">Faltante</small><b class="text-danger">{{ fmt(report.totals.missing_minutes) }}</b></div></div>
            </div>

            <div class="card border-0 shadow-sm">
                <ul class="list-group list-group-flush">
                    <li v-for="d in report.days" :key="d.date" class="list-group-item" :class="{ 'bg-light text-muted': !d.is_workday }">
                        <div class="d-flex justify-content-between">
                            <b>{{ d.label }}</b>
                            <span>{{ fmt(d.worked_minutes) }}</span>
                        </div>
                        <div class="small">
                            <span v-for="(m, i) in d.marks" :key="i" class="me-2" :class="{ 'text-decoration-line-through text-danger': m.discarded, 'text-success': m.source === 'inclusao' }">{{ m.time }}</span>
                            <span v-if="d.holiday" class="badge text-bg-info">{{ d.holiday }}</span>
                            <span v-else-if="d.absent" class="badge text-bg-danger">Falta</span>
                            <span v-if="d.late_minutes" class="badge text-bg-warning ms-1">Atraso {{ d.late_minutes }} min</span>
                        </div>
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>
