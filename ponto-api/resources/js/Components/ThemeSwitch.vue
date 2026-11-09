<script setup>
import { THEMES, setTheme, themePref } from '../theme';

defineProps({ dark: { type: Boolean, default: false } }); // true = sobre fundo escuro (menu lateral)
</script>

<template>
    <div class="theme-switch btn-group w-100" :class="{ 'theme-switch-dark': dark }" role="radiogroup" aria-label="Tema">
        <button
            v-for="t in THEMES"
            :key="t.key"
            type="button"
            class="btn btn-sm"
            :class="themePref === t.key ? 'active' : ''"
            role="radio"
            :aria-checked="themePref === t.key"
            :title="t.key === 'system' ? 'Seguir o tema do dispositivo' : `Tema ${t.label.toLowerCase()}`"
            @click="setTheme(t.key)"
        >
            <i :class="`bi ${t.icon}`"></i><span class="ms-1">{{ t.label }}</span>
        </button>
    </div>
</template>
