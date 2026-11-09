<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { fmtMinutes, fmtMinutesZero } from '../../utils';

const props = defineProps({ employees: Array, report: Object, entries: Array, filters: Object });
const canManage = computed(() => usePage().props.auth.user.can_manage);

const employeeId = ref(props.filters.employee_id);
const month = ref(props.filters.month);
const load = () => router.get('/espelho', { employee_id: employeeId.value, month: month.value }, { preserveState: true });

const pdfUrl = computed(() => `/espelho/pdf?employee_id=${employeeId.value}&month=${month.value}`);
const csvUrl = computed(() => `/espelho/csv?month=${month.value}`);

const adj = useForm({ employee_id: props.filters.employee_id, type: 'inclusao', date: '', time: '', time_entry_id: '', reason: '' });
const showAdj = ref(false);
const openAdj = (date) => {
    adj.reset();
    adj.employee_id = employeeId.value;
    adj.date = date ?? '';
    showAdj.value = true;
};
const submitAdj = () =>
    adj.post('/ajustes', {
        preserveScroll: true,
        onSuccess: () => {
            showAdj.value = false;
            router.reload({ only: ['report', 'entries'] });
        },
    });

const dayEntries = computed(() => props.entries.filter((e) => !adj.date || e.date === adj.date));
</script>

<template>
    <Head title="Espelho de ponto" />
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Espelho de ponto</h1>
        <div class="d-flex gap-2">
            <a v-if="report" :href="pdfUrl" class="btn btn-outline-danger"><i class="bi bi-filetype-pdf"></i> PDF</a>
            <a :href="csvUrl" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV contador (todos)</a>
            <button v-if="canManage && report" class="btn btn-primary" @click="openAdj()"><i class="bi bi-pencil-square"></i> Tratamento</button>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <select v-model="employeeId" class="form-select w-auto" @change="load">
            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
        </select>
        <input v-model="month" type="month" class="form-control w-auto" @change="load" />
    </div>

    <div v-if="!report" class="alert alert-info">Nenhum funcionário disponível.</div>

    <template v-else>
        <div class="row g-3 mb-3">
            <div v-for="[k, label] in [['expected_minutes','Previsto'],['worked_minutes','Trabalhado'],['overtime_50_minutes','HE 50%'],['overtime_100_minutes','HE 100%'],['missing_minutes','Faltante'],['night_minutes','Noturno']]" :key="k" class="col-6 col-md-2">
                <div class="card border-0 shadow-sm"><div class="card-body py-2">
                    <div class="small text-muted">{{ label }}</div>
                    <div class="fs-5 fw-semibold">{{ fmtMinutesZero(report.totals[k]) }}</div>
                </div></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-body">
                <b>{{ report.employee.name }}</b> · CPF {{ report.employee.cpf }} · {{ report.employee.schedule }} · faltas: {{ report.totals.absences }}
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Dia</th><th>Marcações</th><th>Previsto</th><th>Trabalhado</th><th>Atraso</th><th>HE 50%</th><th>HE 100%</th><th>Faltante</th><th>Noturno</th><th>Obs.</th><th v-if="canManage"></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in report.days" :key="d.date" :class="{ 'text-muted': !d.is_workday }">
                            <td class="text-nowrap">{{ d.label }}</td>
                            <td>
                                <span v-for="(m, i) in d.marks" :key="i" class="me-2" :class="{ 'mark-strike': m.discarded, 'mark-inc': m.source !== 'original' }" :title="m.nsr ? `NSR ${m.nsr}` : m.type_label">{{ m.time }}</span>
                            </td>
                            <td>{{ fmtMinutes(d.expected_minutes) }}</td>
                            <td>{{ fmtMinutes(d.worked_minutes) }}</td>
                            <td class="text-danger">{{ fmtMinutes(d.late_minutes) }}</td>
                            <td class="text-success">{{ fmtMinutes(d.overtime_50_minutes) }}</td>
                            <td class="text-success">{{ fmtMinutes(d.overtime_100_minutes) }}</td>
                            <td class="text-danger">{{ fmtMinutes(d.missing_minutes) }}</td>
                            <td>{{ fmtMinutes(d.night_minutes) }}</td>
                            <td class="small">
                                <span v-if="d.holiday" class="badge text-bg-info">{{ d.holiday }}</span>
                                <span v-else-if="d.absent" class="badge text-bg-danger">Falta</span>
                                <span v-else-if="d.incomplete" class="badge text-bg-warning">Incompleto</span>
                                <a v-if="d.requests.some((r) => r.status === 'pendente')" href="/ajustes" class="badge text-bg-warning text-decoration-none ms-1">Ajuste pendente</a>
                            </td>
                            <td v-if="canManage"><button class="btn btn-sm btn-link p-0" title="Tratamento" @click="openAdj(d.date)"><i class="bi bi-pencil-square"></i></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    <!-- Modal de tratamento -->
    <div v-if="showAdj" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.4)">
        <div class="modal-dialog">
            <form class="modal-content" @submit.prevent="submitAdj">
                <div class="modal-header">
                    <h5 class="modal-title">Tratamento de ponto</h5>
                    <button type="button" class="btn-close" @click="showAdj = false"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">A marcação original nunca é alterada; o tratamento fica registrado e auditado (Portaria 671/2021).</p>
                    <div class="mb-2">
                        <label class="form-label">Tipo</label>
                        <select v-model="adj.type" class="form-select">
                            <option value="inclusao">Inclusão de marcação esquecida</option>
                            <option value="alteracao">Alterar horário de uma marcação</option>
                            <option value="desconsideracao">Desconsiderar marcação</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Data</label>
                        <input v-model="adj.date" type="date" class="form-control" :class="{ 'is-invalid': adj.errors.date }" required />
                        <div class="invalid-feedback">{{ adj.errors.date }}</div>
                    </div>
                    <div v-if="adj.type !== 'inclusao'" class="mb-2">
                        <label class="form-label">Marcação</label>
                        <select v-model="adj.time_entry_id" class="form-select" :class="{ 'is-invalid': adj.errors.time_entry_id }" required>
                            <option v-for="e in dayEntries" :key="e.id" :value="e.id">{{ e.label }}</option>
                        </select>
                        <div class="invalid-feedback">{{ adj.errors.time_entry_id }}</div>
                    </div>
                    <div v-if="adj.type !== 'desconsideracao'" class="mb-2">
                        <label class="form-label">{{ adj.type === 'alteracao' ? 'Horário correto' : 'Horário' }}</label>
                        <input v-model="adj.time" type="time" class="form-control" :class="{ 'is-invalid': adj.errors.time }" required />
                        <div class="invalid-feedback">{{ adj.errors.time }}</div>
                    </div>
                    <div>
                        <label class="form-label">Justificativa</label>
                        <textarea v-model="adj.reason" class="form-control" :class="{ 'is-invalid': adj.errors.reason }" rows="3" required></textarea>
                        <div class="invalid-feedback">{{ adj.errors.reason }}</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" @click="showAdj = false">Cancelar</button>
                    <button class="btn btn-primary" :disabled="adj.processing">Registrar</button>
                </div>
            </form>
        </div>
    </div>
</template>
