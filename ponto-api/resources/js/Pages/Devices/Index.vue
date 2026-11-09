<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DeviceStatus from '../../Components/DeviceStatus.vue';
import Pagination from '../../Components/Pagination.vue';

const props = defineProps({ devices: Object, filters: Object });
const errors = computed(() => usePage().props.errors);

const tabs = [
    { key: 'pendente', label: 'Aguardando liberação', icon: 'hourglass-split' },
    { key: 'aprovado', label: 'Liberados', icon: 'phone' },
    { key: 'todos', label: 'Histórico', icon: 'clock-history' },
];

const search = ref(props.filters.search ?? '');
const go = (params) => router.get('/aparelhos', { status: props.filters.status, search: search.value || undefined, ...params }, { preserveState: true });

// Confirmação: liberar, recusar ou desconectar.
const action = ref(null); // { type, device }
const reason = ref('');
const open = (type, device) => {
    action.value = { type, device };
    reason.value = '';
};
const confirm = () => {
    const { type, device } = action.value;
    const path = { approve: 'liberar', reject: 'recusar', revoke: 'desconectar' }[type];
    router.post(`/aparelhos/${device.id}/${path}`, type === 'approve' ? {} : { reason: reason.value }, {
        preserveScroll: true,
        onSuccess: () => (action.value = null),
    });
};
const titles = { approve: 'Liberar aparelho', reject: 'Recusar aparelho', revoke: 'Desconectar aparelho' };
</script>

<template>
    <Head title="Aparelhos" />
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">Aparelhos</h1>
            <small class="text-muted">Cada colaborador bate ponto em um único aparelho liberado pelo RH.</small>
        </div>
        <form class="input-group" style="max-width: 320px" @submit.prevent="go({})">
            <input v-model="search" class="form-control" placeholder="Buscar colaborador" />
            <button class="btn btn-outline-secondary" aria-label="Buscar"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li v-for="t in tabs" :key="t.key" class="nav-item">
            <button class="nav-link" :class="{ active: filters.status === t.key }" @click="go({ status: t.key })">
                <i :class="`bi bi-${t.icon} me-1`"></i>{{ t.label }}
            </button>
        </li>
    </ul>

    <div v-if="errors.status" class="alert alert-danger">{{ errors.status }}</div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Colaborador</th><th>Aparelho</th><th>Solicitado em</th><th>Situação</th><th>Último acesso</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="d in devices.data" :key="d.id">
                        <td><Link :href="`/funcionarios/${d.employee_id}`" class="text-decoration-none">{{ d.employee }}</Link></td>
                        <td>
                            <div class="fw-semibold"><i class="bi bi-phone me-1 text-muted"></i>{{ d.name }}</div>
                            <div v-if="d.replaces" class="small text-warning-emphasis">
                                <i class="bi bi-arrow-left-right"></i> Substituirá: {{ d.replaces }}
                            </div>
                        </td>
                        <td class="text-nowrap small">{{ d.requested_at }}</td>
                        <td>
                            <DeviceStatus :status="d.status" :label="d.status_label" />
                            <div v-if="d.decided_by && d.status === 'aprovado'" class="small text-muted">por {{ d.decided_by }} em {{ d.decided_at }}</div>
                            <div v-if="d.reason && d.status !== 'aprovado'" class="small text-muted fst-italic">{{ d.reason }}</div>
                        </td>
                        <td class="small text-nowrap">{{ d.last_seen_at ?? '—' }}<div class="text-muted">{{ d.last_ip }}</div></td>
                        <td class="text-end text-nowrap">
                            <template v-if="d.status === 'pendente'">
                                <button class="btn btn-sm btn-success me-1" @click="open('approve', d)"><i class="bi bi-check-lg"></i> Liberar</button>
                                <button class="btn btn-sm btn-outline-secondary" @click="open('reject', d)">Recusar</button>
                            </template>
                            <button v-else-if="d.status === 'aprovado'" class="btn btn-sm btn-outline-danger" @click="open('revoke', d)">
                                <i class="bi bi-slash-circle"></i> Desconectar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!devices.data.length">
                        <td colspan="6" class="text-center text-muted py-4">
                            {{ filters.status === 'pendente' ? 'Nenhum aparelho aguardando liberação.' : 'Nenhum aparelho encontrado.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <Pagination :paginator="devices" />

    <div v-if="action" class="modal d-block" style="background: rgba(0,0,0,.4)">
        <div class="modal-dialog">
            <form class="modal-content" @submit.prevent="confirm">
                <div class="modal-header">
                    <h5 class="modal-title">{{ titles[action.type] }}</h5>
                    <button type="button" class="btn-close" aria-label="Fechar" @click="action = null"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2"><b>{{ action.device.employee }}</b> — {{ action.device.name }}</p>
                    <div v-if="action.type === 'approve'" class="alert alert-warning small mb-0">
                        <i class="bi bi-exclamation-triangle"></i>
                        Este passará a ser o <b>único aparelho</b> do colaborador para bater o ponto.
                        <template v-if="action.device.replaces"> O aparelho <b>{{ action.device.replaces }}</b> será desconectado.</template>
                        <template v-else> Qualquer outro aparelho será desconectado.</template>
                    </div>
                    <template v-else>
                        <label class="form-label">Motivo (opcional, o colaborador verá)</label>
                        <input v-model="reason" class="form-control" maxlength="200" :placeholder="action.type === 'revoke' ? 'Ex.: aparelho perdido' : 'Ex.: aparelho pessoal não autorizado'" />
                        <p v-if="action.type === 'revoke'" class="small text-muted mt-2 mb-0">O app será desconectado na próxima ação do colaborador.</p>
                    </template>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" @click="action = null">Cancelar</button>
                    <button class="btn" :class="action.type === 'approve' ? 'btn-success' : 'btn-danger'">{{ titles[action.type].split(' ')[0] }}</button>
                </div>
            </form>
        </div>
    </div>
</template>
