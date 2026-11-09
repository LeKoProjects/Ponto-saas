<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ settings: Object });
const form = useForm({ require_location: props.settings.require_location });
const submit = () => form.post('/configuracoes', { preserveScroll: true });
</script>

<template>
    <Head title="Configurações" />
    <h1 class="h3 mb-3">Configurações da empresa</h1>

    <form class="card border-0 shadow-sm" style="max-width: 720px" @submit.prevent="submit">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-geo-alt"></i> Localização na batida de ponto</div>
        <div class="card-body">
            <div class="form-check form-switch fs-5 mb-2">
                <input id="loc" v-model="form.require_location" class="form-check-input" type="checkbox" role="switch" />
                <label for="loc" class="form-check-label">Exigir localização (GPS) para registrar o ponto</label>
            </div>

            <div v-if="form.require_location" class="alert alert-info small mb-0">
                <b>Ativado:</b> o app pede a localização em toda batida e <b>não registra o ponto</b> se o colaborador negar o acesso ou estiver sem GPS.
                As coordenadas ficam gravadas junto da marcação.
            </div>
            <div v-else class="alert alert-secondary small mb-0">
                <b>Desativado:</b> o app não solicita nem guarda a localização do colaborador.
            </div>

            <p class="small text-muted mt-3 mb-0">
                <i class="bi bi-shield-lock"></i> LGPD: ao ativar, informe os colaboradores sobre a finalidade da coleta (controle de jornada) na política de privacidade.
                Valide com o especialista se a exigência de GPS é compatível com a regra de "não restringir a marcação" da Portaria 671/2021 para o seu caso.
            </p>
        </div>
        <div class="card-footer bg-white text-end">
            <button class="btn btn-primary" :disabled="form.processing || !form.isDirty">Salvar</button>
        </div>
    </form>
</template>
