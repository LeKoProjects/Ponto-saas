<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ThemeSwitch from '../Components/ThemeSwitch.vue';

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
        { href: '/ajustes', icon: 'pencil-square', label: 'Ajustes', badge: page.props.pending_adjustments },
    ];
    if (user.value?.can_manage) {
        items.push(
            { href: '/funcionarios', icon: 'people', label: 'Funcionários' },
            { href: '/aparelhos', icon: 'phone', label: 'Aparelhos', badge: page.props.pending_devices },
            { href: '/jornadas', icon: 'clock', label: 'Jornadas' },
            { href: '/equipes', icon: 'diagram-3', label: 'Equipes' },
            { href: '/auditoria', icon: 'shield-check', label: 'Auditoria' },
            { href: '/arquivos-fiscais', icon: 'file-earmark-lock', label: 'Arquivos fiscais' },
            { href: '/configuracoes', icon: 'gear', label: 'Configurações' },
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
                <img v-if="company?.logo_url" :src="company.logo_url" alt="" class="sidebar-logo mb-2" />
                <div class="fs-5 fw-bold"><i class="bi bi-fingerprint"></i> Ponto SaaS</div>
                <small class="text-white-50">{{ company?.name ?? 'Administração' }}</small>
            </div>
            <nav class="nav nav-pills flex-column gap-1 mb-auto">
                <Link v-for="item in menu" :key="item.href" :href="item.href" class="nav-link" :class="{ active: isActive(item.href) }">
                    <i :class="`bi bi-${item.icon} me-2`"></i>{{ item.label }}
                    <Transition name="pop">
                        <span v-if="item.badge" :key="item.badge" class="badge rounded-pill text-bg-warning ms-1">{{ item.badge }}</span>
                    </Transition>
                </Link>
            </nav>
            <div class="border-top border-secondary pt-3 mt-3">
                <div class="small">{{ user?.name }}</div>
                <div class="small text-white-50 mb-2">{{ user?.role_label }}</div>
                <ThemeSwitch dark class="mb-2" />
                <button class="btn btn-sm btn-outline-light w-100" @click="logout"><i class="bi bi-box-arrow-right"></i> Sair</button>
            </div>
        </aside>

        <main class="flex-grow-1 p-3 p-lg-4">
            <Transition name="alert">
                <div v-if="flash.success" :key="flash.success" class="alert alert-success d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill"></i>{{ flash.success }}
                </div>
            </Transition>
            <Transition name="alert">
                <div v-if="flash.error" :key="flash.error" class="alert alert-danger d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>{{ flash.error }}
                </div>
            </Transition>
            <!-- Troca de página: só anima quando muda a tela (filtros e paginação não piscam). -->
            <Transition name="page" mode="out-in">
                <div :key="page.component">
                    <slot />
                </div>
            </Transition>
        </main>
    </div>
</template>
