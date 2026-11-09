<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '../../Components/Pagination.vue';
import { fmtMinutesZero } from '../../utils';

const props = defineProps({ schedules: Object, weekdays: Object });

const empty = { name: '', start_time: '08:00', end_time: '17:00', daily_minutes: 480, break_minutes: 60, weekdays: [1, 2, 3, 4, 5], tolerance_minutes: 10 };
const editingId = ref(null);
const form = useForm({ ...empty });

const edit = (s) => {
    editingId.value = s.id;
    form.defaults({ ...s, start_time: s.start_time.slice(0, 5), end_time: s.end_time.slice(0, 5) }).reset();
};
const cancel = () => {
    editingId.value = null;
    form.defaults({ ...empty }).reset();
    form.clearErrors();
};
const submit = () =>
    form.post(editingId.value ? `/jornadas/${editingId.value}` : '/jornadas', { preserveScroll: true, onSuccess: cancel });

const days = (list) => list.map((d) => props.weekdays[d]).join(', ');
</script>

<template>
    <Head title="Jornadas" />
    <h1 class="h3 mb-3">Jornadas de trabalho</h1>

    <div class="row g-4">
        <div class="col-xl-4">
            <form class="card border-0 shadow-sm" @submit.prevent="submit">
                <div class="card-header bg-white fw-semibold">{{ editingId ? 'Editar jornada' : 'Nova jornada' }}</div>
                <div class="card-body row g-2">
                    <div class="col-12">
                        <label class="form-label">Nome</label>
                        <input v-model="form.name" class="form-control" placeholder="Comercial 44h" required />
                    </div>
                    <div class="col-6">
                        <label class="form-label">Início</label>
                        <input v-model="form.start_time" type="time" class="form-control" required />
                    </div>
                    <div class="col-6">
                        <label class="form-label">Fim</label>
                        <input v-model="form.end_time" type="time" class="form-control" required />
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jornada diária (min)</label>
                        <input v-model.number="form.daily_minutes" type="number" min="1" class="form-control" required />
                    </div>
                    <div class="col-6">
                        <label class="form-label">Intervalo (min)</label>
                        <input v-model.number="form.break_minutes" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-12">
                        <label class="form-label">Dias de trabalho</label>
                        <div class="d-flex flex-wrap gap-2">
                            <label v-for="(label, d) in weekdays" :key="d" class="btn btn-sm" :class="form.weekdays.includes(Number(d)) ? 'btn-primary' : 'btn-outline-secondary'">
                                <input v-model="form.weekdays" type="checkbox" class="d-none" :value="Number(d)" />{{ label }}
                            </label>
                        </div>
                        <div v-if="form.errors.weekdays" class="text-danger small">{{ form.errors.weekdays }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Tolerância diária (min)</label>
                        <input v-model.number="form.tolerance_minutes" type="number" min="0" max="10" class="form-control" :class="{ 'is-invalid': form.errors.tolerance_minutes }" />
                        <div class="invalid-feedback">{{ form.errors.tolerance_minutes }}</div>
                    </div>
                </div>
                <div class="card-footer bg-white d-flex gap-2 justify-content-end">
                    <button v-if="editingId" type="button" class="btn btn-outline-secondary" @click="cancel">Cancelar</button>
                    <button class="btn btn-primary" :disabled="form.processing">Salvar</button>
                </div>
            </form>
        </div>

        <div class="col-xl-8">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Nome</th><th>Horário</th><th>Diária</th><th>Dias</th><th>Tolerância</th><th>Func.</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="s in schedules.data" :key="s.id">
                                <td>{{ s.name }}</td>
                                <td>{{ s.start_time.slice(0, 5) }}–{{ s.end_time.slice(0, 5) }}</td>
                                <td>{{ fmtMinutesZero(s.daily_minutes) }}</td>
                                <td class="small">{{ days(s.weekdays) }}</td>
                                <td>{{ s.tolerance_minutes }} min</td>
                                <td>{{ s.employees_count }}</td>
                                <td class="text-end"><button class="btn btn-sm btn-outline-primary" @click="edit(s)"><i class="bi bi-pencil"></i></button></td>
                            </tr>
                            <tr v-if="!schedules.data.length"><td colspan="7" class="text-center text-muted py-4">Nenhuma jornada cadastrada.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <Pagination :paginator="schedules" />
        </div>
    </div>
</template>
