<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({ settings: Object, locations: Array, radius: Object });
const form = useForm({
    require_location: props.settings.require_location,
    geofence_enabled: props.settings.geofence_enabled,
    privacy_email: props.settings.privacy_email ?? '',
});
const submit = () => form.post('/configuracoes', { preserveScroll: true });

// Restringir por local exige o GPS ligado.
watch(
    () => form.geofence_enabled,
    (on) => on && (form.require_location = true),
);
const hasLocations = computed(() => props.locations.length > 0);

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

// ---- Locais permitidos (cerca virtual) ----
const emptyLocation = { name: '', latitude: '', longitude: '', radius_m: 150 };
const editingId = ref(null);
const locForm = useForm({ ...emptyLocation });
const coords = ref('');
const geoBusy = ref(false);
const geoError = ref('');

// Aceita "-12.9714, -38.5014" (como o Google Maps copia) ou dois números separados por espaço.
const COORDS_RE = /(-?\d{1,3}(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:[.,]\d+)?)/;
watch(coords, (v) => {
    const m = String(v).match(COORDS_RE);
    locForm.latitude = m ? Number(m[1].replace(',', '.')) : '';
    locForm.longitude = m ? Number(m[2].replace(',', '.')) : '';
});
const coordsValid = computed(() => locForm.latitude !== '' && locForm.longitude !== '');
const mapUrl = (lat, lng) => `https://www.google.com/maps?q=${lat},${lng}`;

const useMyLocation = () => {
    geoError.value = '';
    if (!('geolocation' in navigator)) {
        geoError.value = 'Este navegador não oferece localização.';
        return;
    }
    geoBusy.value = true;
    navigator.geolocation.getCurrentPosition(
        (p) => {
            coords.value = `${p.coords.latitude.toFixed(6)}, ${p.coords.longitude.toFixed(6)}`;
            geoBusy.value = false;
        },
        () => {
            geoError.value = 'Não foi possível obter a localização. Permita o acesso no navegador ou cole as coordenadas.';
            geoBusy.value = false;
        },
        { enableHighAccuracy: true, timeout: 15000 },
    );
};

const editLocation = (l) => {
    editingId.value = l.id;
    locForm.defaults({ name: l.name, latitude: l.latitude, longitude: l.longitude, radius_m: l.radius_m }).reset();
    coords.value = `${l.latitude}, ${l.longitude}`;
    locForm.clearErrors();
};
const cancelLocation = () => {
    editingId.value = null;
    locForm.defaults({ ...emptyLocation }).reset();
    locForm.clearErrors();
    coords.value = '';
};
const saveLocation = () =>
    locForm.post(editingId.value ? `/configuracoes/locais/${editingId.value}` : '/configuracoes/locais', {
        preserveScroll: true,
        onSuccess: cancelLocation,
    });
const removeLocation = (l) => {
    if (confirm(`Remover o local "${l.name}"?`)) router.post(`/configuracoes/locais/${l.id}/remover`, {}, { preserveScroll: true });
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
                        <input id="loc" v-model="form.require_location" class="form-check-input" type="checkbox" role="switch" :disabled="form.geofence_enabled" />
                        <label for="loc" class="form-check-label">Exigir localização (GPS) para registrar o ponto</label>
                    </div>

                    <div v-if="form.require_location" class="alert alert-info small mb-3">
                        <b>Ativado:</b> o app pede a localização em toda batida e <b>não registra o ponto</b> se o colaborador negar o acesso ou estiver sem GPS.
                        As coordenadas ficam gravadas junto da marcação.
                    </div>
                    <div v-else class="alert alert-secondary small mb-3">
                        <b>Desativado:</b> o app não solicita nem guarda a localização do colaborador.
                    </div>

                    <div class="form-check form-switch fs-5 mb-1">
                        <input id="geo" v-model="form.geofence_enabled" class="form-check-input" type="checkbox" role="switch" :disabled="!hasLocations && !form.geofence_enabled" />
                        <label for="geo" class="form-check-label">Permitir bater ponto <b>apenas nos locais cadastrados</b></label>
                    </div>
                    <div v-if="form.errors.geofence_enabled" class="text-danger small mb-1">{{ form.errors.geofence_enabled }}</div>
                    <div class="small text-muted">
                        <template v-if="!hasLocations">Cadastre pelo menos um local em <a href="#locais">Locais permitidos</a> para poder ligar esta opção.</template>
                        <template v-else-if="form.geofence_enabled">
                            O app só registra o ponto dentro do raio de um dos locais cadastrados ({{ locations.length }}). Fora dele, a batida é recusada e o colaborador vê a distância até o local.
                        </template>
                        <template v-else>Desligado: com o GPS ativo, o ponto pode ser batido de qualquer lugar.</template>
                    </div>

                    <p class="small text-muted mt-3 mb-0">
                        <i class="bi bi-shield-lock"></i> LGPD: ao ativar, a <a href="/privacidade" target="_blank">política de privacidade</a> informa os colaboradores sobre a finalidade da coleta (controle de jornada).
                        Valide com o especialista se exigir GPS ou restringir o local é compatível com a regra de "não restringir a marcação" da Portaria 671/2021 para o seu caso
                        (equipes externas, por exemplo, não devem ficar presas a um local).
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

        <!-- Locais permitidos (cerca virtual) -->
        <div id="locais" class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-pin-map"></i> Locais permitidos para bater o ponto</span>
                    <span class="badge" :class="settings.geofence_enabled ? 'text-bg-success' : 'text-bg-secondary'">
                        {{ settings.geofence_enabled ? 'Restrição ligada' : 'Restrição desligada' }}
                    </span>
                </div>
                <div class="row g-0">
                    <div class="col-lg-7 border-end">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr><th>Local</th><th>Coordenadas</th><th>Raio</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="l in locations" :key="l.id" :class="{ 'table-active': editingId === l.id }">
                                        <td class="fw-semibold">{{ l.name }}</td>
                                        <td class="small">
                                            <a :href="mapUrl(l.latitude, l.longitude)" target="_blank" rel="noopener">
                                                {{ Number(l.latitude).toFixed(5) }}, {{ Number(l.longitude).toFixed(5) }} <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </td>
                                        <td>{{ l.radius_m }} m</td>
                                        <td class="text-end text-nowrap">
                                            <button class="btn btn-sm btn-outline-primary me-1" title="Editar" @click="editLocation(l)"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" title="Remover" @click="removeLocation(l)"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <tr v-if="!locations.length">
                                        <td colspan="4" class="text-center text-muted py-4">Nenhum local cadastrado. Cadastre a sede, filiais ou obras ao lado.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <form class="col-lg-5 p-3" @submit.prevent="saveLocation">
                        <div class="fw-semibold mb-2">{{ editingId ? 'Editar local' : 'Novo local' }}</div>
                        <div class="mb-2">
                            <label class="form-label">Nome</label>
                            <input v-model="locForm.name" class="form-control" :class="{ 'is-invalid': locForm.errors.name }" placeholder="Matriz, Filial Centro, Obra…" maxlength="80" required />
                            <div class="invalid-feedback">{{ locForm.errors.name }}</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Coordenadas</label>
                            <div class="input-group has-validation">
                                <input v-model="coords" class="form-control" :class="{ 'is-invalid': locForm.errors.latitude || locForm.errors.longitude }" placeholder="-12.971400, -38.501400" required />
                                <button type="button" class="btn btn-outline-secondary" :disabled="geoBusy" title="Usar a localização deste aparelho" @click="useMyLocation">
                                    <span v-if="geoBusy" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-crosshair"></i>
                                </button>
                                <a v-if="coordsValid" class="btn btn-outline-secondary" :href="mapUrl(locForm.latitude, locForm.longitude)" target="_blank" rel="noopener" title="Conferir no mapa"><i class="bi bi-map"></i></a>
                                <div class="invalid-feedback">{{ locForm.errors.latitude || locForm.errors.longitude }}</div>
                            </div>
                            <div v-if="geoError" class="small text-danger mt-1">{{ geoError }}</div>
                            <div class="form-text">
                                No Google Maps, clique com o botão direito no local e clique nas coordenadas para copiar; depois cole aqui. Ou use <i class="bi bi-crosshair"></i> estando no local.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label d-flex justify-content-between">Raio permitido <b>{{ locForm.radius_m }} m</b></label>
                            <input v-model.number="locForm.radius_m" type="range" class="form-range" :min="radius.min" max="1000" step="10" />
                            <div class="input-group input-group-sm" style="max-width: 180px">
                                <input v-model.number="locForm.radius_m" type="number" class="form-control" :class="{ 'is-invalid': locForm.errors.radius_m }" :min="radius.min" :max="radius.max" />
                                <span class="input-group-text">metros</span>
                            </div>
                            <div v-if="locForm.errors.radius_m" class="small text-danger mt-1">{{ locForm.errors.radius_m }}</div>
                            <div class="form-text">Use pelo menos 100 m: o GPS dentro de prédios costuma variar.</div>
                        </div>
                        <div class="d-flex gap-2 justify-content-end">
                            <button v-if="editingId" type="button" class="btn btn-outline-secondary" @click="cancelLocation">Cancelar</button>
                            <button class="btn btn-primary" :disabled="locForm.processing || !coordsValid">
                                <i class="bi bi-check-lg"></i> {{ editingId ? 'Salvar local' : 'Adicionar local' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
