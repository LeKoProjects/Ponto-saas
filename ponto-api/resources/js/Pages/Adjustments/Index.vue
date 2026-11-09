<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Pagination from '../../Components/Pagination.vue';

const props = defineProps({ adjustments: Object, filters: Object });
const canManage = computed(() => usePage().props.auth.user.can_manage);
const errors = computed(() => usePage().props.errors);

const tabs = [
    { key: 'pendente', label: 'Pendentes' },
    { key: 'aprovado', label: 'Aprovados' },
    { key: 'rejeitado', label: 'Rejeitados' },
    { key: 'todos', label: 'Todos' },
];
const statusCls = { pendente: 'warning', aprovado: 'success', rejeitado: 'danger' };

const filter = (status) => router.get('/ajustes', { status }, { preserveState: true });

const rejecting = ref(null);
const note = ref('');

const approve = (a) => router.post(`/ajustes/${a.id}/aprovar`, {}, { preserveScroll: true });
const openReject = (a) => {
    rejecting.value = a;
    note.value = '';
};
const reject = () =>
    router.post(`/ajustes/${rejecting.value.id}/rejeitar`, { note: note.value }, {
        preserveScroll: true,
        onSuccess: () => (rejecting.value = null),
    });
</script>

<template>
    <Head title="Ajustes de ponto" />
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Ajustes de ponto</h1>
        <Link v-if="canManage" href="/espelho" class="btn btn-outline-primary"><i class="bi bi-pencil-square"></i> Lançar pelo espelho</Link>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li v-for="t in tabs" :key="t.key" class="nav-item">
            <button class="nav-link" :class="{ active: filters.status === t.key }" @click="filter(t.key)">{{ t.label }}</button>
        </li>
    </ul>

    <div v-if="errors.status" class="alert alert-danger">{{ errors.status }}</div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Solicitado em</th><th>Colaborador</th><th>Dia</th><th>Ajuste</th><th>Justificativa</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="a in adjustments.data" :key="a.id">
                        <td class="text-nowrap small">
                            {{ a.created_at }}
                            <div class="text-muted">{{ a.by_employee ? 'pelo colaborador' : `por ${a.requested_by}` }}</div>
                        </td>
                        <td>{{ a.employee }}</td>
                        <td class="text-nowrap">{{ a.date }}</td>
                        <td>
                            <div class="fw-semibold">{{ a.type }}</div>
                            <div class="small">{{ a.detail }}</div>
                        </td>
                        <td class="small" style="max-width: 280px">{{ a.reason }}</td>
                        <td>
                            <span :class="`badge text-bg-${statusCls[a.status]}`">{{ a.status_label }}</span>
                            <div v-if="a.approved_by" class="small text-muted">por {{ a.approved_by }}</div>
                            <div v-if="a.decision_note" class="small text-muted fst-italic">"{{ a.decision_note }}"</div>
                        </td>
                        <td class="text-end text-nowrap">
                            <template v-if="a.can_decide">
                                <button class="btn btn-sm btn-success me-1" @click="approve(a)"><i class="bi bi-check-lg"></i> Aprovar</button>
                                <button class="btn btn-sm btn-outline-danger" @click="openReject(a)"><i class="bi bi-x-lg"></i> Rejeitar</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!adjustments.data.length"><td colspan="7" class="text-center text-muted py-4">{{ filters.status === 'todos' ? 'Nenhum ajuste.' : `Nenhum ajuste ${filters.status}.` }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <Pagination :paginator="adjustments" />

    <div v-if="rejecting" class="modal d-block" style="background: rgba(0,0,0,.4)">
        <div class="modal-dialog">
            <form class="modal-content" @submit.prevent="reject">
                <div class="modal-header">
                    <h5 class="modal-title">Rejeitar solicitação</h5>
                    <button type="button" class="btn-close" @click="rejecting = null"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2"><b>{{ rejecting.employee }}</b> — {{ rejecting.date }} — {{ rejecting.detail }}</p>
                    <label class="form-label">Motivo (o colaborador verá no app)</label>
                    <textarea v-model="note" class="form-control" :class="{ 'is-invalid': errors.note }" rows="3" maxlength="300" required></textarea>
                    <div class="invalid-feedback">{{ errors.note }}</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" @click="rejecting = null">Cancelar</button>
                    <button class="btn btn-danger">Rejeitar</button>
                </div>
            </form>
        </div>
    </div>
</template>
