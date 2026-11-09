<script setup>
import { onMounted, ref } from 'vue';
import { auth, post } from './api';
import LoginView from './views/LoginView.vue';
import PunchView from './views/PunchView.vue';
import HistoryView from './views/HistoryView.vue';

const loggedIn = ref(!!auth.token);
const tab = ref('ponto');
const TABS = ['ponto', 'historico'];
const direction = ref('left'); // sentido da troca de aba

const go = (next) => {
    if (next === tab.value) return;
    direction.value = TABS.indexOf(next) > TABS.indexOf(tab.value) ? 'left' : 'right';
    tab.value = next;
    window.scrollTo({ top: 0, behavior: 'instant' });
};

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
        <Transition name="fade" mode="out-in">
            <LoginView v-if="!loggedIn" key="login" @logged-in="loggedIn = true" />
            <div v-else key="app">
                <Transition :name="`tab-${direction}`" mode="out-in">
                    <PunchView v-if="tab === 'ponto'" key="ponto" @logout="logout" />
                    <HistoryView v-else key="historico" />
                </Transition>
                <nav class="tabbar d-flex">
                    <span class="tab-indicator" :style="{ transform: `translateX(${TABS.indexOf(tab) * 100}%)` }"></span>
                    <button :class="{ active: tab === 'ponto' }" @click="go('ponto')"><i class="bi bi-fingerprint"></i>Ponto</button>
                    <button :class="{ active: tab === 'historico' }" @click="go('historico')"><i class="bi bi-calendar3"></i>Espelho</button>
                </nav>
            </div>
        </Transition>
    </div>
</template>
