<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '../../Components/Pagination.vue';

const props = defineProps({ employees: Object, filters: Object });
const search = ref(props.filters.search ?? '');
const doSearch = () => router.get('/funcionarios', search.value ? { search: search.value } : {}, { preserveState: true });
</script>

<template>
    <Head title="Funcionários" />
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Funcionários</h1>
        <Link href="/funcionarios/novo" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Novo funcionário</Link>
    </div>

    <form class="input-group mb-3" style="max-width: 420px" @submit.prevent="doSearch">
        <input v-model="search" class="form-control" placeholder="Nome, CPF ou matrícula" />
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Nome</th><th>CPF</th><th>Matrícula</th><th>Perfil</th><th>Jornada</th><th>Equipe</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="e in employees.data" :key="e.id">
                        <td>{{ e.name }}<div class="small text-muted">{{ e.email }}</div></td>
                        <td>{{ e.cpf }}</td>
                        <td>{{ e.registration ?? '—' }}</td>
                        <td>{{ e.role }}</td>
                        <td>{{ e.schedule }}</td>
                        <td>{{ e.team ?? '—' }}</td>
                        <td><span :class="`badge text-bg-${e.active ? 'success' : 'secondary'}`">{{ e.active ? 'Ativo' : 'Inativo' }}</span></td>
                        <td class="text-end"><Link :href="`/funcionarios/${e.id}`" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></Link></td>
                    </tr>
                    <tr v-if="!employees.data.length"><td colspan="8" class="text-center text-muted py-4">Nenhum funcionário encontrado.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <Pagination :paginator="employees" />
</template>
