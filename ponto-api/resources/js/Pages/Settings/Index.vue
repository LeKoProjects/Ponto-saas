<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({ settings: Object });
const form = useForm({
    require_location: props.settings.require_location,
    privacy_email: props.settings.privacy_email ?? '',
});
const submit = () => form.post('/configuracoes', { preserveScroll: true });

// ---- Logo ----
const logoForm = useForm({ logo: null });
const preview = ref(null);
const fileInput = ref(null);

const pick = (e) => {
    const file = e.target.files?.[0] ?? null;
    logoForm.logo = file;
    logoForm.clearErrors();
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = file ? URL.createObjectURL(file) : null;
};
const resetPick = () => {
    logoForm.reset();
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
    if (fileInput.value) fileInput.value.value = '';
};
const uploadLogo = () =>
    logoForm.post('/configuracoes/logo', { forceFormData: true, preserveScroll: true, onSuccess: resetPick });
const removeLogo = () => {
    if (confirm('Remover a logo da empresa?')) router.post('/configuracoes/logo/remover', {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Configurações" />
    <h1 class="h3 mb-3">Configurações da empresa</h1>

    <div class="row g-3 align-items-start">
        <!-- Logo -->
        <div class="col-xl-5">
        <form class="card border-0 shadow-sm" @submit.prevent="uploadLogo">
            <div class="card-header bg-body fw-semibold"><i class="bi bi-image"></i> Logo da empresa</div>
            <div class="card-body d-flex flex-wrap gap-3 align-items-center">
                <img v-if="preview || settings.logo_url" :src="preview || settings.logo_url" alt="Logo" class="logo-preview" />
                <div v-else class="logo-preview d-flex align-items-center justify-content-center text-muted small">Sem logo</div>
                <div class="flex-grow-1">
                    <input ref="fileInput" type="file" class="form-control" :class="{ 'is-invalid': logoForm.errors.logo }" accept="image/png,image/jpeg,image/webp" @change="pick" />
                    <div class="invalid-feedback">{{ logoForm.errors.logo }}</div>
                    <div class="form-text">PNG, JPG ou WEBP, até 1 MB. Prefira fundo transparente. Aparece no painel, no app do colaborador, no espelho em PDF e no e-mail do comprovante.</div>
                </div>
            </div>
            <div class="card-footer bg-body d-flex justify-content-between">
                <button v-if="settings.logo_url && !preview" type="button" class="btn btn-outline-danger" @click="removeLogo"><i class="bi bi-trash"></i> Remover</button>
                <button v-else-if="preview" type="button" class="btn btn-outline-secondary" @click="resetPick">Cancelar</button>
                <span v-else></span>
                <button class="btn btn-primary" :disabled="!logoForm.logo || logoForm.processing"><i class="bi bi-upload"></i> Enviar logo</button>
            </div>
        </form>
        </div>

        <div class="col-xl-7">
        <form class="card border-0 shadow-sm" @submit.prevent="submit">
            <div class="card-header bg-body fw-semibold"><i class="bi bi-geo-alt"></i> Localização na batida de ponto</div>
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
                    <i class="bi bi-shield-lock"></i> LGPD: ao ativar, a <a href="/privacidade" target="_blank">política de privacidade</a> informa os colaboradores sobre a finalidade da coleta (controle de jornada).
                    Valide com o especialista se a exigência de GPS é compatível com a regra de "não restringir a marcação" da Portaria 671/2021 para o seu caso.
                </p>
            </div>

            <div class="card-header bg-body fw-semibold border-top"><i class="bi bi-shield-check"></i> Privacidade (LGPD)</div>
            <div class="card-body">
                <label class="form-label">E-mail do encarregado de dados (DPO) da empresa</label>
                <input v-model="form.privacy_email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.privacy_email }" placeholder="privacidade@suaempresa.com.br" />
                <div class="invalid-feedback">{{ form.errors.privacy_email }}</div>
                <div class="form-text">Aparece na <a href="/privacidade" target="_blank">política de privacidade</a> do app para o colaborador exercer seus direitos (acesso, correção etc.).</div>
            </div>
            <div class="card-footer bg-body text-end">
                <button class="btn btn-primary" :disabled="form.processing || !form.isDirty">Salvar</button>
            </div>
        </form>
        </div>
    </div>
</template>
