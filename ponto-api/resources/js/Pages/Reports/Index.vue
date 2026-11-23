<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Pagination from '../../Components/Pagination.vue';

const props = defineProps({ types: Object, filters: Object, teams: Array, employees: Array, report: Object });

const f = reactive({ ...props.filters });
const params = (extra = {}) => {
    const p = { type: f.type, from: f.from, to: f.to, ...extra };
    if (f.team_id) p.team_id = f.team_id;
    if (f.employee_id) p.employee_id = f.employee_id;
    return Object.fromEntries(Object.entries({ ...p, ...extra }).filter(([, v]) => v !== null && v !== '' && v !== undefined));
};
const load = (extra = {}) => router.get('/relatorios', params(extra), { preserveState: true, preserveScroll: true });

const pick = (type) => {
    f.type = type;
    load();
};
const setEmployee = (id) => {
    f.employee_id = id;
    load();
};
const changeTeam = () => {
    f.employee_id = null;
    load();
};

// Atalhos de período.
const iso = (d) => d.toISOString().slice(0, 10);
const preset = (key) => {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    if (key === 'mes') [f.from, f.to] = [iso(new Date(Date.UTC(y, m, 1))), iso(now)];
    if (key === 'anterior') [f.from, f.to] = [iso(new Date(Date.UTC(y, m - 1, 1))), iso(new Date(Date.UTC(y, m, 0)))];
    if (key === '7') [f.from, f.to] = [iso(new Date(now - 6 * 864e5)), iso(now)];
    if (key === '30') [f.from, f.to] = [iso(new Date(now - 29 * 864e5)), iso(now)];
    load();
};

const exportUrl = (format) => `/relatorios/exportar/${format}?${new URLSearchParams(params())}`;
const employeeName = computed(() => props.employees.find((e) => e.id === Number(f.employee_id))?.name);
const isCell = (c) => c !== null && typeof c === 'object';
</script>

<template>
    <Head title="Relatórios" />
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Relatórios</h1>
            <div class="text-muted small">Geral (todos) ou individual: escolha um colaborador ou clique no nome na tabela.</div>
        </div>
        <div class="d-flex gap-2">
            <a :href="exportUrl('csv')" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
            <a :href="exportUrl('pdf')" class="btn btn-outline-danger"><i class="bi bi-filetype-pdf"></i> PDF</a>
        </div>
    </div>

    <!-- Tipos de relatório -->
    <div class="report-types mb-3">
        <button
            v-for="(t, key) in types"
            :key="key"
            type="button"
            class="report-type"
            :class="{ active: f.type === key }"
            :aria-pressed="f.type === key"
            @click="pick(key)"
        >
            <i :class="`bi ${t.icon}`"></i>
            <span>{{ t.label }}</span>
        </button>
    </div>

    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">De</label>
                <input v-model="f.from" type="date" class="form-control" @change="load()" />
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Até</label>
                <input v-model="f.to" type="date" class="form-control" @change="load()" />
            </div>
            <div class="col-12 col-md-3 d-flex flex-wrap gap-1">
                <button class="btn btn-sm btn-outline-secondary" @click="preset('mes')">Este mês</button>
                <button class="btn btn-sm btn-outline-secondary" @click="preset('anterior')">Mês passado</button>
                <button class="btn btn-sm btn-outline-secondary" @click="preset('7')">7 dias</button>
                <button class="btn btn-sm btn-outline-secondary" @click="preset('30')">30 dias</button>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Equipe</label>
                <select v-model="f.team_id" class="form-select" @change="changeTeam">
                    <option :value="null">Todas</option>
                    <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Colaborador</label>
                <select v-model="f.employee_id" class="form-select" @change="load()">
                    <option :value="null">Todos (geral)</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Cabeçalho do relatório -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h2 class="h5 mb-0">{{ report.title }}</h2>
        <span class="badge" :class="report.mode === 'geral' ? 'text-bg-primary' : 'text-bg-info'">{{ report.mode === 'geral' ? 'Geral' : 'Individual' }}</span>
        <button v-if="report.mode === 'individual'" class="btn btn-sm btn-link" @click="setEmployee(null)"><i class="bi bi-arrow-left"></i> Voltar para o geral</button>
    </div>
    <p class="text-muted small mb-3">{{ types[report.type].description }} · {{ report.subtitle }}</p>

    <!-- Resumo -->
    <div class="row g-2 mb-3">
        <div v-for="s in report.summary" :key="s.label" class="col-6 col-md">
            <div class="card border-0 shadow-sm h-100 stat-card">
                <div class="card-body py-2">
                    <div class="small text-muted">{{ s.label }}</div>
                    <div class="fs-4 fw-semibold" :class="s.tone && s.value !== 0 && s.value !== '00:00' ? `text-${s.tone}` : ''">{{ s.value }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabela -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 report-table">
                <thead class="table-light">
                    <tr><th v-for="c in report.columns" :key="c.key" class="text-nowrap">{{ c.label }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in report.rows.data" :key="i">
                        <td v-for="c in report.columns" :key="c.key">
                            <template v-if="c.key === 'colaborador' && row.employee_id">
                                <a href="#" class="fw-semibold text-decoration-none" title="Ver individual" @click.prevent="setEmployee(row.employee_id)">
                                    {{ row.cells[c.key] }} <i class="bi bi-chevron-right small"></i>
                                </a>
                            </template>
                            <template v-else-if="isCell(row.cells[c.key]) && row.cells[c.key].badges">
                                <span v-for="b in row.cells[c.key].badges" :key="b.text" class="badge me-1 mb-1" :class="`text-bg-${b.tone}`">{{ b.text }}</span>
                            </template>
                            <template v-else-if="isCell(row.cells[c.key]) && row.cells[c.key].href">
                                <a :href="row.cells[c.key].href" target="_blank" rel="noopener" class="text-nowrap"><i class="bi bi-map"></i> {{ row.cells[c.key].text }}</a>
                            </template>
                            <template v-else-if="isCell(row.cells[c.key]) && row.cells[c.key].badge">
                                <span class="badge" :class="`text-bg-${row.cells[c.key].tone}`">{{ row.cells[c.key].text }}</span>
                            </template>
                            <template v-else-if="isCell(row.cells[c.key])">
                                <span :class="`text-${row.cells[c.key].tone} fw-semibold`">{{ row.cells[c.key].text }}</span>
                            </template>
                            <template v-else>{{ row.cells[c.key] }}</template>
                        </td>
                    </tr>
                    <tr v-if="!report.rows.data.length">
                        <td :colspan="report.columns.length" class="text-center text-muted py-4">
                            <i class="bi bi-check2-circle"></i> Nada a mostrar no período{{ employeeName ? ` para ${employeeName}` : '' }}.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <Pagination :paginator="report.rows" />

    <ul v-if="report.notes.length" class="small text-muted mt-3 ps-3">
        <li v-for="n in report.notes" :key="n" class="mb-1">{{ n }}</li>
    </ul>
</template>

<style scoped>
.report-types {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 8px;
}
.report-type {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid var(--bs-border-color);
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    font-size: 0.9rem;
    font-weight: 500;
    text-align: left;
    transition:
        border-color var(--fast) var(--ease),
        background-color var(--fast) var(--ease),
        transform var(--fast) var(--ease);
}
.report-type i {
    font-size: 1.2rem;
    color: var(--ponto-primary);
}
.report-type:hover {
    border-color: var(--ponto-primary);
}
.report-type.active {
    background: var(--ponto-primary);
    border-color: var(--ponto-primary);
    color: #fff;
}
.report-type.active i {
    color: #fff;
}
.report-type:active {
    transform: scale(0.98);
}
.report-table td {
    white-space: nowrap;
}
</style>
