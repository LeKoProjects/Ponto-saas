<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '../../Components/Pagination.vue';

defineProps({ teams: Object, managers: Array });

const nullManager = (d) => ({ ...d, manager_user_id: d.manager_user_id || null });

// Nova equipe (card à esquerda)
const form = useForm({ name: '', manager_user_id: '' });
const submit = () => {
    form.transform(nullManager).post('/equipes', { preserveScroll: true, onSuccess: () => form.reset() });
};

// Editar equipe (modal)
const editing = ref(null);
const editForm = useForm({ name: '', manager_user_id: '' });
const edit = (t) => {
    editing.value = t;
    editForm.clearErrors();
    editForm.name = t.name;
    editForm.manager_user_id = t.manager_user_id ?? '';
};
const closeEdit = () => {
    editing.value = null;
};
const submitEdit = () => {
    editForm.transform(nullManager).post(`/equipes/${editing.value.id}`, { preserveScroll: true, onSuccess: closeEdit });
};
</script>

<template>
    <Head title="Equipes" />
    <h1 class="h3 mb-3">Equipes</h1>
    <div class="row g-4">
        <div class="col-xl-4">
            <form class="card border-0 shadow-sm" @submit.prevent="submit">
                <div class="card-header bg-body fw-semibold">Nova equipe</div>
                <div class="card-body">
                    <label class="form-label">Nome</label>
                    <input v-model="form.name" class="form-control mb-3" :class="{ 'is-invalid': form.errors.name }" required />
                    <div v-if="form.errors.name" class="invalid-feedback d-block mt-n2 mb-3">{{ form.errors.name }}</div>
                    <label class="form-label">Gestor</label>
                    <select v-model="form.manager_user_id" class="form-select" :class="{ 'is-invalid': form.errors.manager_user_id }">
                        <option value="">Sem gestor</option>
                        <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                    <div v-if="form.errors.manager_user_id" class="invalid-feedback">{{ form.errors.manager_user_id }}</div>
                </div>
                <div class="card-footer bg-body d-flex gap-2 justify-content-end align-items-center">
                    <span v-if="form.recentlySuccessful" class="text-success small me-auto"><i class="bi bi-check-circle-fill"></i> Equipe cadastrada.</span>
                    <button class="btn btn-primary" :disabled="form.processing">Salvar</button>
                </div>
            </form>
        </div>
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>Equipe</th><th>Gestor</th><th>Funcionários</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="t in teams.data" :key="t.id">
                            <td>{{ t.name }}</td>
                            <td>{{ t.manager?.name ?? '—' }}</td>
                            <td>{{ t.employees_count }}</td>
                            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" title="Editar" @click="edit(t)"><i class="bi bi-pencil"></i></button></td>
                        </tr>
                        <tr v-if="!teams.data.length"><td colspan="4" class="text-center text-muted py-4">Nenhuma equipe.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="teams" />
        </div>
    </div>

    <div v-if="editing" class="modal d-block" style="background: rgba(0,0,0,.4)" @click.self="closeEdit">
        <div class="modal-dialog">
            <form class="modal-content" @submit.prevent="submitEdit">
                <div class="modal-header">
                    <h5 class="modal-title">Editar equipe</h5>
                    <button type="button" class="btn-close" aria-label="Fechar" @click="closeEdit"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Nome</label>
                    <input v-model="editForm.name" class="form-control mb-3" :class="{ 'is-invalid': editForm.errors.name }" required />
                    <div v-if="editForm.errors.name" class="invalid-feedback d-block mt-n2 mb-3">{{ editForm.errors.name }}</div>
                    <label class="form-label">Gestor</label>
                    <select v-model="editForm.manager_user_id" class="form-select" :class="{ 'is-invalid': editForm.errors.manager_user_id }">
                        <option value="">Sem gestor</option>
                        <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                    <div v-if="editForm.errors.manager_user_id" class="invalid-feedback">{{ editForm.errors.manager_user_id }}</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" @click="closeEdit">Cancelar</button>
                    <button class="btn btn-primary" :disabled="editForm.processing">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</template>
