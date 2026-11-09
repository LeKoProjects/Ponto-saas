<script setup>
import { onMounted, ref } from 'vue';
import { auth, post } from './api';
import LoginView from './views/LoginView.vue';
import PunchView from './views/PunchView.vue';
import HistoryView from './views/HistoryView.vue';
import SettingsView from './views/SettingsView.vue';

const loggedIn = ref(!!auth.token);
const tab = ref('ponto');
const TABS = [
    { key: 'ponto', label: 'Ponto', icon: 'bi-fingerprint' },
    { key: 'historico', label: 'Espelho', icon: 'bi-calendar3' },
    { key: 'config', label: 'Configurações', icon: 'bi-gear' },
];
const tabIndex = (key) => TABS.findIndex((t) => t.key === key);
const direction = ref('left'); // sentido da troca de aba

const go = (next) => {
    if (next === tab.value) return;
    direction.value = tabIndex(next) > tabIndex(tab.value) ? 'left' : 'right';
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
                    <HistoryView v-else-if="tab === 'historico'" key="historico" />
                    <SettingsView v-else key="config" @logout="logout" />
                </Transition>
                <nav class="tabbar d-flex">
                    <span class="tab-indicator" :style="{ width: `${100 / TABS.length}%`, transform: `translateX(${tabIndex(tab) * 100}%)` }"></span>
                    <button v-for="t in TABS" :key="t.key" :class="{ active: tab === t.key }" :aria-current="tab === t.key ? 'page' : null" @click="go(t.key)">
                        <i :class="`bi ${t.icon}`"></i>{{ t.label }}
                    </button>
                </nav>
            </div>
        </Transition>
    </div>
</template>
