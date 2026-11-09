<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({ data: Object, teams: Array, filters: Object });
const teamId = ref(props.filters.team_id ?? '');

const statusInfo = {
    trabalhando: { label: 'Trabalhando', cls: 'success' },
    intervalo: { label: 'Intervalo', cls: 'warning' },
    encerrado: { label: 'Encerrado', cls: 'secondary' },
    ausente: { label: 'Ausente', cls: 'danger' },
    aguardando: { label: 'Aguardando', cls: 'info' },
    folga: { label: 'Folga', cls: 'light' },
};

const cards = [
    { key: 'trabalhando', label: 'Trabalhando agora', icon: 'person-check', cls: 'success' },
    { key: 'intervalo', label: 'Em intervalo', icon: 'cup-hot', cls: 'warning' },
    { key: 'atrasados', label: 'Atrasados hoje', icon: 'alarm', cls: 'orange' },
    { key: 'ausente', label: 'Ausentes', icon: 'person-x', cls: 'danger' },
];

const filter = () => router.get('/', teamId.value ? { team_id: teamId.value } : {}, { preserveState: true });

// Atualiza a cada 60 s sem modal (reload parcial).
let interval;
onMounted(() => (interval = setInterval(() => router.reload({ only: ['data'], showProgress: false }), 60000)));
onUnmounted(() => clearInterval(interval));
</script>

<template>
    <Head title="Agora" />
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-0">Quem está trabalhando</h1>
            <small class="text-muted">{{ data.date }} · atualizado às {{ data.time }}<span v-if="data.is_holiday"> · feriado</span></small>
        </div>
        <select v-if="teams.length" v-model="teamId" class="form-select w-auto" @change="filter">
            <option value="">Todas as equipes</option>
            <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
    </div>

    <div class="row g-3 mb-4">
        <div v-for="c in cards" :key="c.key" class="col-6 col-xl-3">
            <div class="card stat-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small"><i :class="`bi bi-${c.icon}`"></i> {{ c.label }}</div>
                    <div class="display-6">{{ data.counters[c.key] }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Funcionário</th><th>Equipe</th><th>Situação</th><th>Previsto</th><th>Marcações</th><th>Atraso</th></tr>
                </thead>
                <tbody>
                    <tr v-for="r in data.rows" :key="r.id">
                        <td>{{ r.name }}</td>
                        <td>{{ r.team ?? '—' }}</td>
                        <td><span :class="`badge text-bg-${statusInfo[r.status].cls}`">{{ statusInfo[r.status].label }}</span></td>
                        <td>{{ r.expected_start }}</td>
                        <td><span v-for="(m, i) in r.marks" :key="i" class="me-2">{{ m }}</span></td>
                        <td><span v-if="r.late_minutes" class="text-danger">{{ r.late_minutes }} min</span></td>
                    </tr>
                    <tr v-if="!data.rows.length"><td colspan="6" class="text-center text-muted py-4">Nenhum funcionário ativo.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
