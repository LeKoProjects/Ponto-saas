<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '../../Components/Pagination.vue';
import { maskCnpj } from '../../utils';

const props = defineProps({ companies: Object, plans: Object, filters: Object, metrics: Object });

const search = ref(props.filters.search ?? '');
const doSearch = () => router.get('/admin/empresas', search.value ? { search: search.value } : {}, { preserveState: true });

const editingId = ref(null);
const empty = { cnpj: '', legal_name: '', trade_name: '', plan: 'essencial', status: 'active', owner_name: '', owner_email: '', owner_password: '' };
const form = useForm({ ...empty });

const edit = (c) => {
    editingId.value = c.id;
    form.defaults({ ...empty, ...c, cnpj: maskCnpj(c.cnpj), trade_name: c.trade_name ?? '' }).reset();
};
const cancel = () => {
    editingId.value = null;
    form.defaults({ ...empty }).reset();
    form.clearErrors();
};
const submit = () => form.post(editingId.value ? `/admin/empresas/${editingId.value}` : '/admin/empresas', { preserveScroll: true, onSuccess: cancel });

const toggle = (c) =>
    router.post(`/admin/empresas/${c.id}`, { ...c, status: c.status === 'active' ? 'blocked' : 'active' }, { preserveScroll: true });
</script>

<template>
    <Head title="Empresas" />
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Empresas clientes</h1>
        <div class="d-flex gap-3">
            <div class="text-end"><div class="small text-muted">Ativas</div><div class="fs-5 fw-semibold">{{ metrics.active }}</div></div>
            <div class="text-end"><div class="small text-muted">MRR</div><div class="fs-5 fw-semibold">R$ {{ metrics.mrr.toLocaleString('pt-BR') }}</div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <form class="card border-0 shadow-sm" @submit.prevent="submit">
                <div class="card-header bg-body fw-semibold">{{ editingId ? 'Editar empresa' : 'Nova empresa' }}</div>
                <div class="card-body row g-2">
                    <div class="col-12">
                        <label class="form-label">CNPJ</label>
                        <input :value="form.cnpj" class="form-control" :class="{ 'is-invalid': form.errors.cnpj }" required @input="form.cnpj = maskCnpj($event.target.value)" />
                        <div class="invalid-feedback">{{ form.errors.cnpj }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Razão social</label>
                        <input v-model="form.legal_name" class="form-control" required />
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nome fantasia</label>
                        <input v-model="form.trade_name" class="form-control" />
                    </div>
                    <div class="col-6">
                        <label class="form-label">Plano</label>
                        <select v-model="form.plan" class="form-select">
                            <option v-for="(p, k) in plans" :key="k" :value="k">{{ p.label }} — R$ {{ p.price }}</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Status</label>
                        <select v-model="form.status" class="form-select">
                            <option value="active">Ativa</option>
                            <option value="blocked">Bloqueada</option>
                        </select>
                    </div>
                    <template v-if="!editingId">
                        <div class="col-12 mt-3 fw-semibold">Usuário dono</div>
                        <div class="col-12"><input v-model="form.owner_name" class="form-control" placeholder="Nome" required /></div>
                        <div class="col-12">
                            <input v-model="form.owner_email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.owner_email }" placeholder="E-mail" required />
                            <div class="invalid-feedback">{{ form.errors.owner_email }}</div>
                        </div>
                        <div class="col-12"><input v-model="form.owner_password" type="password" class="form-control" placeholder="Senha inicial (mín. 8)" required autocomplete="new-password" /></div>
                    </template>
                </div>
                <div class="card-footer bg-body d-flex gap-2 justify-content-end">
                    <button v-if="editingId" type="button" class="btn btn-outline-secondary" @click="cancel">Cancelar</button>
                    <button class="btn btn-primary" :disabled="form.processing">Salvar</button>
                </div>
            </form>
        </div>

        <div class="col-xl-8">
            <form class="input-group mb-3" style="max-width: 400px" @submit.prevent="doSearch">
                <input v-model="search" class="form-control" placeholder="Buscar empresa ou CNPJ" />
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </form>
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Empresa</th><th>CNPJ</th><th>Plano</th><th>Funcionários</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="c in companies.data" :key="c.id">
                                <td>{{ c.trade_name || c.legal_name }}<div class="small text-muted">{{ c.legal_name }}</div></td>
                                <td class="text-nowrap">{{ maskCnpj(c.cnpj) }}</td>
                                <td>{{ plans[c.plan]?.label }}</td>
                                <td>{{ c.employees_count }} / {{ plans[c.plan]?.max_employees }}</td>
                                <td><span :class="`badge text-bg-${c.status === 'active' ? 'success' : 'danger'}`">{{ c.status === 'active' ? 'Ativa' : 'Bloqueada' }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary me-1" @click="edit(c)"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm" :class="c.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success'" @click="toggle(c)">
                                        {{ c.status === 'active' ? 'Bloquear' : 'Ativar' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!companies.data.length"><td colspan="6" class="text-center text-muted py-4">Nenhuma empresa.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <Pagination :paginator="companies" />
        </div>
    </div>
</template>
