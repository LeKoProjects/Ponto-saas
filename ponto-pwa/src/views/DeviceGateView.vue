<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { post } from '../api';
import { session } from '../session';

const props = defineProps({ device: { type: Object, required: true } });
const emit = defineEmits(['approved', 'logout']);

const current = ref(props.device);
const checking = ref(false);
const message = ref('');
let timer;

/** Consulta o status do aparelho; quando o RH liberar, entra no app. */
const check = async (manual = false) => {
    if (checking.value) return;
    checking.value = true;
    if (manual) message.value = '';
    try {
        const { device } = await post('/aparelho');
        current.value = device;
        session.saveDevice(device);
        if (device.status === 'aprovado') {
            emit('approved', device);
        } else if (manual) {
            message.value = 'Ainda aguardando o RH liberar este aparelho.';
        }
    } catch (e) {
        if (manual) message.value = e.message;
    } finally {
        checking.value = false;
    }
};

onMounted(() => {
    timer = setInterval(() => check(false), 20000); // confere sozinho a cada 20 s
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <div class="pt-5 text-center enter-up">
        <div class="gate-icon mb-4" :class="current.status">
            <i class="bi" :class="current.status === 'pendente' ? 'bi-hourglass-split' : 'bi-phone-vibrate'"></i>
        </div>

        <h1 class="h4 fw-bold mb-2">Aguardando liberação do RH</h1>
        <p class="text-muted mb-4">
            Por segurança, o ponto só pode ser registrado em <b>um aparelho</b> liberado pela sua empresa.
            Avise o RH que você entrou por este celular.
        </p>

        <div class="card border-0 shadow-sm text-start mb-4">
            <div class="card-body">
                <div class="small text-muted">Este aparelho</div>
                <div class="fw-semibold mb-2"><i class="bi bi-phone me-1"></i>{{ current.name }}</div>
                <div class="d-flex justify-content-between small">
                    <span class="text-muted">Solicitado em</span><span>{{ current.requested_at }}</span>
                </div>
                <div class="d-flex justify-content-between small">
                    <span class="text-muted">Situação</span>
                    <span class="badge text-bg-warning">{{ current.status_label }}</span>
                </div>
            </div>
        </div>

        <button class="btn btn-primary btn-lg w-100 mb-2" :disabled="checking" @click="check(true)">
            <span v-if="checking" class="spinner-border spinner-border-sm me-2"></span>
            <i v-else class="bi bi-arrow-repeat me-1"></i>Verificar agora
        </button>
        <Transition name="alert">
            <p v-if="message" class="small text-muted">{{ message }}</p>
        </Transition>
        <p class="small text-muted mb-4">Esta tela atualiza sozinha assim que o RH liberar.</p>

        <button class="btn btn-link text-danger" @click="$emit('logout')">Sair</button>
    </div>
</template>

<style scoped>
.gate-icon {
    width: 96px;
    height: 96px;
    margin: 0 auto;
    border-radius: 50%;
    background: var(--bs-warning-bg-subtle);
    color: var(--bs-warning-text-emphasis);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.6rem;
    animation: gate-pulse 2.4s var(--ease) infinite;
}

.gate-icon i {
    animation: gate-turn 2.4s var(--ease) infinite;
}

@keyframes gate-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.35);
    }
    50% {
        box-shadow: 0 0 0 14px rgba(255, 193, 7, 0);
    }
}

@keyframes gate-turn {
    0%,
    40% {
        transform: rotate(0);
    }
    60%,
    100% {
        transform: rotate(180deg);
    }
}
</style>
