<script setup>
import { onMounted, ref } from 'vue';
import { auth, post } from './api';
import LoginView from './views/LoginView.vue';
import PunchView from './views/PunchView.vue';
import HistoryView from './views/HistoryView.vue';

const loggedIn = ref(!!auth.token);
const tab = ref('ponto');

const logout = async () => {
    try {
        await post('/logout');
    } catch {
        /* token já inválido */
    }
    auth.clear();
    loggedIn.value = false;
};

onMounted(() => window.addEventListener('ponto:logout', () => (loggedIn.value = false)));
</script>

<template>
    <div class="app">
        <LoginView v-if="!loggedIn" @logged-in="loggedIn = true" />
        <template v-else>
            <PunchView v-if="tab === 'ponto'" @logout="logout" />
            <HistoryView v-else />
            <nav class="tabbar d-flex">
                <button :class="{ active: tab === 'ponto' }" @click="tab = 'ponto'"><i class="bi bi-fingerprint"></i>Ponto</button>
                <button :class="{ active: tab === 'historico' }" @click="tab = 'historico'"><i class="bi bi-calendar3"></i>Meu mês</button>
            </nav>
        </template>
    </div>
</template>
