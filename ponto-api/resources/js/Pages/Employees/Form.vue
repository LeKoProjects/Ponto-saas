<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import DeviceStatus from '../../Components/DeviceStatus.vue';
import { maskCpf } from '../../utils';

const props = defineProps({ employee: { type: Object, default: null }, schedules: Array, teams: Array, devices: { type: Array, default: () => [] } });

const form = useForm({
    name: props.employee?.name ?? '',
    email: props.employee?.email ?? '',
    password: '',
    role: props.employee?.role ?? 'funcionario',
    cpf: maskCpf(props.employee?.cpf ?? ''),
    registration: props.employee?.registration ?? '',
    admission_date: props.employee?.admission_date ?? new Date().toISOString().slice(0, 10),
    work_schedule_id: props.employee?.work_schedule_id ?? props.schedules[0]?.id ?? '',
    team_id: props.employee?.team_id ?? '',
    active: props.employee?.active ?? true,
});

const submit = () => {
    form.transform((d) => ({ ...d, team_id: d.team_id || null }));
    form.post(props.employee ? `/funcionarios/${props.employee.id}` : '/funcionarios');
};
</script>

<template>
    <Head :title="employee ? 'Editar funcionário' : 'Novo funcionário'" />
    <h1 class="h3 mb-3">{{ employee ? 'Editar funcionário' : 'Novo funcionário' }}</h1>

    <div v-if="!schedules.length" class="alert alert-warning">
        Cadastre uma <Link href="/jornadas">jornada de trabalho</Link> antes de cadastrar funcionários.
    </div>

    <form class="card border-0 shadow-sm" @submit.prevent="submit">
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">Nome completo</label>
                <input v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" required />
                <div class="invalid-feedback">{{ form.errors.name }}</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">E-mail (login no app)</label>
                <input v-model="form.email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.email }" required />
                <div class="invalid-feedback">{{ form.errors.email }}</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">CPF</label>
                <input :value="form.cpf" class="form-control" :class="{ 'is-invalid': form.errors.cpf }" required @input="form.cpf = maskCpf($event.target.value)" />
                <div class="invalid-feedback">{{ form.errors.cpf }}</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Matrícula</label>
                <input v-model="form.registration" class="form-control" />
            </div>
            <div class="col-md-4">
                <label class="form-label">Admissão</label>
                <input v-model="form.admission_date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.admission_date }" required />
            </div>
            <div class="col-md-4">
                <label class="form-label">Jornada</label>
                <select v-model="form.work_schedule_id" class="form-select" :class="{ 'is-invalid': form.errors.work_schedule_id }" required>
                    <option v-for="s in schedules" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <div class="invalid-feedback">{{ form.errors.work_schedule_id }}</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Equipe</label>
                <select v-model="form.team_id" class="form-select">
                    <option value="">Sem equipe</option>
                    <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Perfil</label>
                <select v-model="form.role" class="form-select">
                    <option value="funcionario">Funcionário (só app)</option>
                    <option value="gestor">Gestor</option>
                    <option value="rh">RH</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ employee ? 'Nova senha (deixe em branco para manter)' : 'Senha inicial' }}</label>
                <input v-model="form.password" type="password" class="form-control" :class="{ 'is-invalid': form.errors.password }" :required="!employee" autocomplete="new-password" />
                <div class="invalid-feedback">{{ form.errors.password }}</div>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check form-switch">
                    <input id="active" v-model="form.active" type="checkbox" class="form-check-input" />
                    <label for="active" class="form-check-label">Ativo</label>
                </div>
            </div>
        </div>
        <div class="card-footer bg-body d-flex gap-2 justify-content-end">
            <Link href="/funcionarios" class="btn btn-outline-secondary">Cancelar</Link>
            <button class="btn btn-primary" :disabled="form.processing">Salvar</button>
        </div>
    </form>

    <!-- Histórico de aparelhos -->
    <div v-if="employee" class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-body d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-phone"></i> Aparelhos do colaborador</span>
            <Link href="/aparelhos" class="small">Gerenciar aparelhos</Link>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Aparelho</th><th>Solicitado em</th><th>Situação</th><th>Liberado / recusado</th><th>Desconectado</th><th>Último acesso</th></tr>
                </thead>
                <tbody>
                    <tr v-for="d in devices" :key="d.id">
                        <td>{{ d.name }}</td>
                        <td class="small text-nowrap">{{ d.requested_at }}</td>
                        <td><DeviceStatus :status="d.status" :label="d.status_label" /></td>
                        <td class="small">{{ d.decided ?? '—' }}</td>
                        <td class="small">{{ d.revoked ?? '—' }}<div v-if="d.reason" class="text-muted fst-italic">{{ d.reason }}</div></td>
                        <td class="small text-nowrap">{{ d.last_seen_at ?? '—' }}</td>
                    </tr>
                    <tr v-if="!devices.length"><td colspan="6" class="text-center text-muted py-3">Nenhum aparelho. O colaborador ainda não entrou no app.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
