<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { post } from '../api';

defineEmits(['logout']);

const me = ref(null);
const error = ref('');
const loading = ref(false);
const receipt = ref(null);
const locating = ref(false);

// Relógio exibido com base na hora do SERVIDOR (offset calculado no /me).
const offset = ref(0);
const now = ref(new Date());
let tick;

const clock = computed(() => now.value.toLocaleTimeString('pt-BR', { timeZone: 'America/Bahia' }));
const today = computed(() => now.value.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', timeZone: 'America/Bahia' }));

const load = async () => {
    try {
        me.value = await post('/me');
        offset.value = new Date(me.value.server_time).getTime() - Date.now();
    } catch (e) {
        error.value = e.message;
    }
};

const GEO_ERRORS = {
    1: 'Permissão de localização negada. Libere o acesso à localização para este app nas configurações do navegador e tente de novo.',
    2: 'Não foi possível obter sua localização. Ative o GPS do celular e tente de novo.',
    3: 'A localização demorou para responder. Verifique o GPS e tente de novo.',
};

/** Localização: só quando a empresa exige — e aí é obrigatória. */
const getPosition = () =>
    new Promise((resolve, reject) => {
        if (!me.value?.require_location) return resolve({});
        if (!navigator.geolocation) return reject(new Error('Este aparelho não oferece localização, que é obrigatória na sua empresa.'));
        locating.value = true;
        navigator.geolocation.getCurrentPosition(
            (p) => {
                locating.value = false;
                resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude });
            },
            (err) => {
                locating.value = false;
                reject(new Error(GEO_ERRORS[err.code] ?? GEO_ERRORS[2]));
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    });

const punch = async () => {
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    try {
        const coords = await getPosition();
        const res = await post('/ponto', coords);
        receipt.value = res.receipt;
        if (navigator.vibrate) navigator.vibrate(80);
        await load();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};

const showReceipt = async (id) => {
    try {
        receipt.value = await post(`/comprovante/${id}`);
    } catch (e) {
        error.value = e.message;
    }
};

onMounted(() => {
    load();
    tick = setInterval(() => (now.value = new Date(Date.now() + offset.value)), 1000);
});
onUnmounted(() => clearInterval(tick));
</script>

<template>
    <div class="pt-3">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <div class="fw-semibold">{{ me?.user.name ?? '…' }}</div>
                <small class="text-muted">{{ me?.company }}</small>
            </div>
            <button class="btn btn-sm btn-outline-secondary" @click="$emit('logout')"><i class="bi bi-box-arrow-right"></i></button>
        </div>

        <div class="text-center mb-4">
            <div class="clock">{{ clock }}</div>
            <div class="text-muted text-capitalize">{{ today }}</div>
            <small v-if="me" class="text-muted">Jornada {{ me.schedule.start }}–{{ me.schedule.end }}</small>
        </div>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <div class="text-center mb-4">
            <button class="btn btn-primary punch-btn" :disabled="loading || !me" @click="punch">
                <template v-if="loading">
                    <span class="spinner-border"></span>
                    <span v-if="locating" class="d-block small mt-2">Obtendo localização…</span>
                </template>
                <template v-else><i class="bi bi-fingerprint"></i>{{ me?.next_type_label ?? 'Registrar' }}</template>
            </button>
        </div>

        <p v-if="me?.require_location" class="text-center small text-muted mb-4">
            <i class="bi bi-geo-alt-fill text-primary"></i> Sua empresa exige a localização para registrar o ponto.
        </p>
        <div v-else class="mb-4"></div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Marcações de hoje</div>
            <ul class="list-group list-group-flush">
                <li v-for="e in me?.today ?? []" :key="e.id" class="list-group-item d-flex justify-content-between align-items-center">
                    <span><b>{{ e.time.slice(0, 5) }}</b> · {{ e.type_label }}</span>
                    <button class="btn btn-sm btn-link" @click="showReceipt(e.id)">Comprovante</button>
                </li>
                <li v-if="me && !me.today.length" class="list-group-item text-muted text-center">Nenhuma marcação hoje.</li>
            </ul>
        </div>

        <!-- Comprovante -->
        <div v-if="receipt" class="modal d-block" style="background: rgba(0,0,0,.45)" @click.self="receipt = null">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-check-circle-fill text-success"></i> Ponto registrado</h5>
                        <button class="btn-close" @click="receipt = null"></button>
                    </div>
                    <div class="modal-body small">
                        <p class="text-muted mb-2">Comprovante de Registro de Ponto do Trabalhador</p>
                        <dl class="row mb-0">
                            <dt class="col-4">Data</dt><dd class="col-8">{{ receipt.data }}</dd>
                            <dt class="col-4">Hora</dt><dd class="col-8 fs-5 fw-bold">{{ receipt.hora }}</dd>
                            <dt class="col-4">Tipo</dt><dd class="col-8">{{ receipt.tipo }}</dd>
                            <dt class="col-4">NSR</dt><dd class="col-8">{{ String(receipt.nsr).padStart(9, '0') }}</dd>
                            <dt class="col-4">Empregador</dt><dd class="col-8">{{ receipt.empregador }}</dd>
                            <dt class="col-4">CNPJ</dt><dd class="col-8">{{ receipt.cnpj }}</dd>
                            <dt class="col-4">Trabalhador</dt><dd class="col-8">{{ receipt.trabalhador }}</dd>
                            <dt class="col-4">CPF</dt><dd class="col-8">{{ receipt.cpf }}</dd>
                            <dt class="col-4">Hash</dt><dd class="col-8 hash">{{ receipt.hash }}</dd>
                        </dl>
                        <p class="text-muted mt-2 mb-0">Uma cópia foi enviada para seu e-mail.</p>
                    </div>
                    <div class="modal-footer"><button class="btn btn-primary w-100" @click="receipt = null">OK</button></div>
                </div>
            </div>
        </div>
    </div>
</template>
