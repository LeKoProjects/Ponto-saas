<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { ApiError, post } from '../api';
import { geo } from '../geo';
import { session, uuid } from '../session';

defineEmits(['logout']);

// Abre na hora com o que está salvo no aparelho; o /me só atualiza.
const profile = ref(session.profile());
const cachedToday = session.today();
const entries = ref(cachedToday?.entries ?? []);
const nextTypeLabel = ref(cachedToday?.nextTypeLabel ?? 'Registrar');
const synced = ref(false);

const error = ref('');
const loading = ref(false);
const status = ref(''); // texto auxiliar durante a batida
const receipt = ref(null);
const success = ref(false); // botão verde com ✓ logo após registrar
let successTimer;

// Relógio exibido com base na hora do SERVIDOR (offset calculado no /me).
const offset = ref(0);
const now = ref(new Date());
let tick;

const clock = computed(() => now.value.toLocaleTimeString('pt-BR', { timeZone: 'America/Bahia' }));
const todayLabel = computed(() => now.value.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', timeZone: 'America/Bahia' }));

const applyToday = (list, nextType, label) => {
    entries.value = list;
    nextTypeLabel.value = label;
    session.saveToday(list, nextType, label);
};

const load = async () => {
    try {
        const me = await post('/me');
        offset.value = new Date(me.server_time).getTime() - Date.now();
        profile.value = me.profile;
        session.saveProfile(me.profile);
        if (me.device) session.saveDevice(me.device);
        applyToday(me.today, me.next_type, me.next_type_label);
        synced.value = true;
    } catch (e) {
        if (!profile.value) error.value = e.message;
    }
};

// GPS: só quando a empresa exige; começa a acompanhar já ao abrir a tela.
watch(
    () => profile.value?.require_location,
    (required) => (required ? geo.start() : geo.stop()),
    { immediate: true },
);

/** Envia a batida; em falha de rede tenta de novo com o MESMO request_id (sem duplicar). */
const sendPunch = async (body) => {
    try {
        return await post('/ponto', body);
    } catch (e) {
        if (e instanceof ApiError && e.status === 0) {
            status.value = 'Reconectando…';
            await new Promise((r) => setTimeout(r, 1500));
            return post('/ponto', body);
        }
        throw e;
    }
};

const punch = async () => {
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    status.value = '';
    try {
        const body = { request_id: uuid() };
        if (profile.value?.require_location) {
            status.value = 'Obtendo localização…';
            Object.assign(body, await geo.current());
        }
        status.value = 'Registrando…';
        const res = await sendPunch(body);

        // A resposta já traz tudo: comprovante, marcações de hoje e próximo tipo.
        applyToday(res.today, res.next_type, res.next_type_label);
        if (navigator.vibrate) navigator.vibrate(80);

        // Confirmação visual curta antes de abrir o comprovante.
        success.value = true;
        clearTimeout(successTimer);
        successTimer = setTimeout(() => (success.value = false), 1600);
        setTimeout(() => (receipt.value = res.receipt), 450);
    } catch (e) {
        error.value = e.message;
        // A regra de GPS pode ter mudado: atualiza o perfil.
        if (e.errors?.latitude || e.status === 403) load();
    } finally {
        loading.value = false;
        status.value = '';
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
onUnmounted(() => {
    clearInterval(tick);
    clearTimeout(successTimer);
    geo.stop();
});
</script>

<template>
    <div class="pt-3">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <div class="fw-semibold">{{ profile?.name ?? '…' }}</div>
                <small class="text-muted">{{ profile?.company }}</small>
            </div>
            <button class="btn btn-sm btn-outline-secondary" aria-label="Sair" @click="$emit('logout')"><i class="bi bi-box-arrow-right"></i></button>
        </div>

        <div class="text-center mb-4">
            <div class="clock">{{ clock }}</div>
            <div class="text-muted text-capitalize">{{ todayLabel }}</div>
            <small v-if="profile" class="text-muted">Jornada {{ profile.schedule.start }}–{{ profile.schedule.end }}</small>
        </div>

        <Transition name="alert">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>
        </Transition>

        <div class="text-center mb-4">
            <span class="punch-wrap" :class="{ busy: loading, done: success }">
                <button class="btn btn-primary punch-btn" :class="{ success }" :disabled="loading || success || !profile" @click="punch">
                    <template v-if="success"><i class="bi bi-check-lg"></i>Registrado!</template>
                    <template v-else-if="loading">
                        <span class="spinner-border"></span>
                        <Transition name="fade" mode="out-in">
                            <span v-if="status" :key="status" class="d-block small mt-2">{{ status }}</span>
                        </Transition>
                    </template>
                    <template v-else><i class="bi bi-fingerprint"></i>{{ nextTypeLabel }}</template>
                </button>
            </span>
        </div>

        <p v-if="profile?.require_location" class="text-center small text-muted mb-4">
            <i class="bi bi-geo-alt-fill text-primary"></i> Sua empresa exige a localização para registrar o ponto.
        </p>
        <div v-else class="mb-4"></div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                Marcações de hoje
                <span v-if="!synced" class="spinner-border spinner-border-sm text-secondary" title="Atualizando"></span>
            </div>
            <TransitionGroup tag="ul" name="list" class="list-group list-group-flush">
                <li v-for="e in entries" :key="e.id" class="list-group-item d-flex justify-content-between align-items-center">
                    <span><b>{{ e.time.slice(0, 5) }}</b> · {{ e.type_label }}</span>
                    <button class="btn btn-sm btn-link" @click="showReceipt(e.id)">Comprovante</button>
                </li>
                <li v-if="!entries.length" key="empty" class="list-group-item text-muted text-center">Nenhuma marcação hoje.</li>
            </TransitionGroup>
        </div>

        <!-- Comprovante (folha inferior) -->
        <Teleport to="body">
            <Transition name="sheet">
                <div v-if="receipt" class="sheet-backdrop" @click.self="receipt = null">
                    <div class="sheet" role="dialog" aria-labelledby="receipt-title">
                        <div class="sheet-handle"></div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h5 id="receipt-title" class="mb-0"><i class="bi bi-check-circle-fill text-success"></i> Ponto registrado</h5>
                            <button class="btn-close" aria-label="Fechar" @click="receipt = null"></button>
                        </div>
                        <p class="text-muted small mb-3">Comprovante de Registro de Ponto do Trabalhador</p>
                        <dl class="row small mb-0">
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
                        <p class="text-muted small mt-2">Uma cópia foi enviada para seu e-mail.</p>
                        <button class="btn btn-primary btn-lg w-100" @click="receipt = null">OK</button>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
