<script setup>
import { computed, onMounted, ref } from 'vue';
import { post, webUrl } from '../api';
import { describeDevice } from '../device';
import { prefs, session } from '../session';
import { THEMES, setTheme, themeApplied, themePref } from '../theme';

defineEmits(['logout']);

const profile = ref(session.profile());
const device = ref(null);
const binding = ref(session.device()); // liberação do RH para este aparelho
const privacyUrl = computed(() => webUrl(`/privacidade${profile.value?.company_id ? `?empresa=${profile.value.company_id}` : ''}`));

// ---- Sincronização ----
const syncing = ref(false);
const syncMessage = ref('');
const syncError = ref(false);
const lastSync = ref(prefs.get('last_sync'));

const lastSyncLabel = computed(() => {
    if (!lastSync.value) return 'Nunca sincronizado neste aparelho';
    const d = new Date(lastSync.value);
    return `Última sincronização: ${d.toLocaleDateString('pt-BR')} às ${d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`;
});

const sync = async () => {
    if (syncing.value) return;
    syncing.value = true;
    syncMessage.value = '';
    syncError.value = false;
    try {
        const me = await post('/me');
        session.saveProfile(me.profile);
        session.saveToday(me.today, me.next_type, me.next_type_label);
        if (me.device) {
            session.saveDevice(me.device);
            binding.value = me.device;
        }
        profile.value = me.profile;
        lastSync.value = new Date().toISOString();
        prefs.set('last_sync', lastSync.value);
        syncMessage.value = 'Dados atualizados com o servidor.';
    } catch (e) {
        syncError.value = true;
        syncMessage.value = e.message;
    } finally {
        syncing.value = false;
        setTimeout(() => (syncMessage.value = ''), 5000);
    }
};

// ---- Biometria (somente a preferência; a confirmação será implementada depois) ----
const biometric = ref(prefs.get('biometric') === true);
const toggleBiometric = () => {
    biometric.value = !biometric.value;
    prefs.set('biometric', biometric.value);
};

onMounted(async () => {
    device.value = await describeDevice();
});
</script>

<template>
    <div class="pt-3">
        <h1 class="h5 fw-semibold mb-3">Configurações</h1>
        <div class="settings-grid">

        <section class="settings-account">
        <!-- Conta -->
        <div class="card border-0 shadow-sm mb-3 enter-up">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="avatar">{{ (profile?.name ?? '?').charAt(0) }}</div>
                <div class="min-w-0">
                    <div class="fw-semibold text-truncate">{{ profile?.name ?? '—' }}</div>
                    <div class="small text-muted text-truncate">{{ profile?.email }}</div>
                    <div class="small text-muted text-truncate">{{ profile?.company }}</div>
                </div>
            </div>
        </div>

        <!-- Aparelho -->
        </section>
        <section>
        <div class="section-title">Aparelho</div>
        <div class="card border-0 shadow-sm mb-3 enter-up">
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex align-items-center gap-3">
                    <i class="bi bi-phone fs-4 text-primary"></i>
                    <div class="min-w-0 flex-grow-1">
                        <div class="small text-muted">Nome do aparelho</div>
                        <div class="fw-semibold text-truncate">
                            <span v-if="device">{{ device.name }}</span>
                            <span v-else class="placeholder-glow"><span class="placeholder col-8"></span></span>
                        </div>
                        <div v-if="device" class="small text-muted">{{ device.browser }} · {{ device.standalone ? 'App instalado' : 'No navegador' }}</div>
                    </div>
                </li>
                <li v-if="binding" class="list-group-item d-flex align-items-center gap-3">
                    <i class="bi fs-4" :class="binding.status === 'aprovado' ? 'bi-shield-check text-success' : 'bi-hourglass-split text-warning'"></i>
                    <div class="min-w-0 flex-grow-1">
                        <div class="fw-semibold">{{ binding.status === 'aprovado' ? 'Aparelho liberado pelo RH' : binding.status_label }}</div>
                        <div class="small text-muted">
                            <template v-if="binding.status === 'aprovado' && binding.decided_at">Desde {{ binding.decided_at }} · </template>
                            Este é o único aparelho em que você pode bater o ponto.
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Sincronização -->
        </section>
        <section>
        <div class="section-title">Sincronização</div>
        <div class="card border-0 shadow-sm mb-3 enter-up">
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Atualiza seus dados, a jornada, as regras da empresa e as marcações de hoje com o servidor.
                </p>
                <button class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2" :disabled="syncing" @click="sync">
                    <i class="bi bi-arrow-repeat" :class="{ spinning: syncing }"></i>
                    {{ syncing ? 'Sincronizando…' : 'Sincronizar dados' }}
                </button>
                <Transition name="alert">
                    <div v-if="syncMessage" class="small mt-2" :class="syncError ? 'text-danger' : 'text-success'">
                        <i :class="syncError ? 'bi bi-exclamation-circle' : 'bi bi-check-circle'"></i> {{ syncMessage }}
                    </div>
                </Transition>
                <div class="small text-muted mt-2">{{ lastSyncLabel }}</div>
            </div>
        </div>

        <!-- Segurança -->
        </section>
        <section>
        <div class="section-title">Segurança</div>
        <div class="card border-0 shadow-sm mb-3 enter-up">
            <ul class="list-group list-group-flush">
                <li class="list-group-item">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-fingerprint fs-4 text-primary"></i>
                        <label for="bio" class="flex-grow-1 mb-0">
                            <div class="fw-semibold">Bater ponto com biometria</div>
                            <div class="small text-muted">Pedir digital ou rosto antes de registrar</div>
                        </label>
                        <div class="form-check form-switch m-0">
                            <input id="bio" class="form-check-input fs-4" type="checkbox" role="switch" :checked="biometric" @change="toggleBiometric" />
                        </div>
                    </div>
                    <div class="small mt-2 text-muted d-flex align-items-start gap-1">
                        <span class="badge text-bg-warning">Em breve</span>
                        <span>A confirmação por biometria ainda não está ativa: por enquanto só guardamos sua preferência.</span>
                    </div>
                </li>
            </ul>
        </div>

        </section>
        <section>
        <!-- Aparência -->
        <div class="section-title">Aparência</div>
        <div class="card border-0 shadow-sm mb-3 enter-up">
            <div class="card-body">
                <div class="theme-options" role="radiogroup" aria-label="Tema do aplicativo">
                    <button
                        v-for="t in THEMES"
                        :key="t.key"
                        type="button"
                        class="theme-option"
                        :class="{ active: themePref === t.key }"
                        role="radio"
                        :aria-checked="themePref === t.key"
                        @click="setTheme(t.key)"
                    >
                        <span class="theme-preview" :class="`preview-${t.key}`"><i :class="`bi ${t.icon}`"></i></span>
                        <span class="small fw-semibold">{{ t.label }}</span>
                    </button>
                </div>
                <div class="small text-muted mt-2">
                    <template v-if="themePref === 'system'">
                        Segue o tema do seu aparelho (agora: {{ themeApplied === 'dark' ? 'escuro' : 'claro' }}) e muda sozinho quando ele mudar.
                    </template>
                    <template v-else>Tema {{ themePref === 'dark' ? 'escuro' : 'claro' }} sempre, independente do aparelho.</template>
                </div>
            </div>
        </div>
        </section>
        <section>
        <!-- Privacidade -->
        <div class="section-title">Privacidade</div>
        <div class="card border-0 shadow-sm mb-3 enter-up">
            <a :href="privacyUrl" target="_blank" rel="noopener" class="list-group-item list-group-item-action d-flex align-items-center gap-3 p-3 border-0 rounded">
                <i class="bi bi-shield-lock fs-4 text-primary"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold">Política de privacidade</div>
                    <div class="small text-muted">Quais dados usamos, por quanto tempo e seus direitos (LGPD)</div>
                </div>
                <i class="bi bi-box-arrow-up-right text-muted"></i>
            </a>
        </div>

        </section>
        </div>

        <button class="btn btn-outline-danger w-100 mb-3 settings-logout" @click="$emit('logout')">
            <i class="bi bi-box-arrow-right"></i> Sair da conta
        </button>
    </div>
</template>

<style scoped>
.section-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--bs-secondary-color);
    margin: 0 4px 6px;
}
.avatar {
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    border-radius: 50%;
    background: var(--bs-primary);
    color: #fff;
    font-weight: 600;
    font-size: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
.min-w-0 {
    min-width: 0;
}
.spinning {
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
.form-switch .form-check-input {
    cursor: pointer;
    transition:
        background-position var(--base) var(--ease),
        background-color var(--base) var(--ease);
}
.theme-options {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}
.theme-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 10px 6px;
    border: 2px solid var(--bs-border-color);
    border-radius: 14px;
    background: none;
    color: var(--bs-body-color);
    transition:
        border-color var(--base) var(--ease),
        transform var(--fast) var(--ease);
}
.theme-option:active {
    transform: scale(0.97);
}
.theme-option.active {
    border-color: var(--bs-primary);
    color: var(--bs-primary);
}
.theme-preview {
    width: 100%;
    height: 44px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    border: 1px solid var(--bs-border-color);
}
.preview-light {
    background: #f4f6fb;
    color: #f59f00;
}
.preview-dark {
    background: #121417;
    color: #9ec5fe;
}
.preview-system {
    background: linear-gradient(135deg, #f4f6fb 50%, #121417 50%);
    color: var(--bs-primary);
}
</style>
