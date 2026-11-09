<script setup>
import { ref } from 'vue';
import { auth, post } from '../api';

const emit = defineEmits(['logged-in']);
const email = ref('');
const password = ref('');
const error = ref('');
const loading = ref(false);

const submit = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { token } = await post('/login', { email: email.value, password: password.value, device_name: navigator.userAgent.slice(0, 100) });
        auth.set(token);
        emit('logged-in');
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="pt-5">
        <div class="text-center mb-4">
            <img src="/favicon.svg" width="72" height="72" alt="" class="mb-3" />
            <h1 class="h3 fw-bold">Registro de ponto</h1>
            <p class="text-muted">Entre com o e-mail cadastrado pelo RH</p>
        </div>
        <form class="card border-0 shadow-sm" @submit.prevent="submit">
            <div class="card-body p-4">
                <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
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
    </div>
</template>
