<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '../../Components/Pagination.vue';

defineProps({ teams: Object, managers: Array });

const editingId = ref(null);
const form = useForm({ name: '', manager_user_id: '' });

const edit = (t) => {
    editingId.value = t.id;
    form.defaults({ name: t.name, manager_user_id: t.manager_user_id ?? '' }).reset();
};
const cancel = () => {
    editingId.value = null;
    form.defaults({ name: '', manager_user_id: '' }).reset();
};
const submit = () => {
    form.transform((d) => ({ ...d, manager_user_id: d.manager_user_id || null }));
    form.post(editingId.value ? `/equipes/${editingId.value}` : '/equipes', { preserveScroll: true, onSuccess: cancel });
};
</script>

<template>
    <Head title="Equipes" />
    <h1 class="h3 mb-3">Equipes</h1>
    <div class="row g-4">
        <div class="col-xl-4">
            <form class="card border-0 shadow-sm" @submit.prevent="submit">
                <div class="card-header bg-body fw-semibold">{{ editingId ? 'Editar equipe' : 'Nova equipe' }}</div>
                <div class="card-body">
                    <label class="form-label">Nome</label>
                    <input v-model="form.name" class="form-control mb-3" :class="{ 'is-invalid': form.errors.name }" required />
                    <label class="form-label">Gestor</label>
                    <select v-model="form.manager_user_id" class="form-select">
                        <option value="">Sem gestor</option>
                        <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                </div>
                <div class="card-footer bg-body d-flex gap-2 justify-content-end">
                    <button v-if="editingId" type="button" class="btn btn-outline-secondary" @click="cancel">Cancelar</button>
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
                            <td class="text-end"><button class="btn btn-sm btn-outline-primary" @click="edit(t)"><i class="bi bi-pencil"></i></button></td>
                        </tr>
                        <tr v-if="!teams.data.length"><td colspan="4" class="text-center text-muted py-4">Nenhuma equipe.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="teams" />
        </div>
    </div>
</template>
