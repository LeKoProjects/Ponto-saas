<script setup>
import { ref } from 'vue';
import { auth, post } from '../api';
import { describeDevice } from '../device';
import { deviceId, session } from '../session';

const props = defineProps({ notice: { type: String, default: '' } });
const emit = defineEmits(['logged-in']);
const email = ref('');
const password = ref('');
const error = ref('');
const loading = ref(false);

const submit = async () => {
    loading.value = true;
    error.value = '';
    try {
        const info = await describeDevice();
        const { token, device } = await post('/login', {
            email: email.value,
            password: password.value,
            device_id: deviceId(),
            device_name: `${info.name} · ${info.browser}`,
        });
        auth.set(token);
        session.saveDevice(device);
        emit('logged-in', device);
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="pt-5 enter-up">
        <div class="text-center mb-4">
            <img src="/favicon.svg" width="72" height="72" alt="" class="mb-3" />
            <h1 class="h3 fw-bold">Registro de ponto</h1>
            <p class="text-muted">Entre com o e-mail cadastrado pelo RH</p>
        </div>
        <Transition name="alert">
            <div v-if="props.notice && !error" class="alert alert-warning small d-flex gap-2">
                <i class="bi bi-info-circle-fill"></i><span>{{ props.notice }}</span>
            </div>
        </Transition>
        <form class="card border-0 shadow-sm" @submit.prevent="submit">
            <div class="card-body p-4">
                <Transition name="alert">
                    <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
                </Transition>
                <div class="mb-3">
                    <label class="form-label">E-mail</label>
                    <input v-model="email" type="email" class="form-control form-control-lg" autocomplete="username" required />
                </div>
                <div class="mb-4">
                    <label class="form-label">Senha</label>
                    <input v-model="password" type="password" class="form-control form-control-lg" autocomplete="current-password" required />
                </div>
                <button class="btn btn-primary btn-lg w-100" :disabled="loading">
                    <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>Entrar
                </button>
            </div>
        </form>
        <p class="small text-muted text-center mt-3">
            <i class="bi bi-phone"></i> No primeiro acesso, este aparelho precisa ser liberado pelo RH.
        </p>
    </div>
</template>
