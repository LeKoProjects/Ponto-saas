<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth.user);
const company = computed(() => page.props.auth.company);
const flash = computed(() => page.props.flash);

const menu = computed(() => {
    if (user.value?.role === 'super_admin') {
        return [{ href: '/admin/empresas', icon: 'building', label: 'Empresas' }];
    }
    const items = [
        { href: '/', icon: 'speedometer2', label: 'Agora' },
        { href: '/espelho', icon: 'calendar3', label: 'Espelho de ponto' },
    ];
    if (user.value?.can_manage) {
        items.push(
            { href: '/funcionarios', icon: 'people', label: 'Funcionários' },
            { href: '/jornadas', icon: 'clock', label: 'Jornadas' },
            { href: '/equipes', icon: 'diagram-3', label: 'Equipes' },
            { href: '/ajustes', icon: 'pencil-square', label: 'Tratamentos' },
            { href: '/auditoria', icon: 'shield-check', label: 'Auditoria' },
        );
    }
    return items;
});

const isActive = (href) => (href === '/' ? page.url === '/' || page.url.startsWith('/?') : page.url.startsWith(href));
const logout = () => router.post('/logout');
</script>

<template>
    <div class="d-lg-flex">
        <aside class="sidebar bg-dark text-white p-3 d-flex flex-column">
            <div class="mb-4">
                <div class="fs-5 fw-bold"><i class="bi bi-fingerprint"></i> Ponto SaaS</div>
                <small class="text-white-50">{{ company?.name ?? 'Administração' }}</small>
            </div>
            <nav class="nav nav-pills flex-column gap-1 mb-auto">
                <Link v-for="item in menu" :key="item.href" :href="item.href" class="nav-link" :class="{ active: isActive(item.href) }">
                    <i :class="`bi bi-${item.icon} me-2`"></i>{{ item.label }}
                </Link>
            </nav>
            <div class="border-top border-secondary pt-3 mt-3">
                <div class="small">{{ user?.name }}</div>
                <div class="small text-white-50 mb-2">{{ user?.role_label }}</div>
                <button class="btn btn-sm btn-outline-light w-100" @click="logout"><i class="bi bi-box-arrow-right"></i> Sair</button>
            </div>
        </aside>

        <main class="flex-grow-1 p-3 p-lg-4">
            <div v-if="flash.success" class="alert alert-success alert-dismissible">{{ flash.success }}</div>
            <div v-if="flash.error" class="alert alert-danger">{{ flash.error }}</div>
            <slot />
        </main>
    </div>
</template>
