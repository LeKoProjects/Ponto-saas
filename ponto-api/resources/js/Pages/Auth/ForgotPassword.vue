<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const sent = computed(() => page.props.flash?.success);
const form = useForm({ email: '' });
const submit = () => form.post('/esqueci-senha', { preserveScroll: true });
</script>

<template>
    <Head title="Esqueci minha senha" />
    <div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
        <div class="card shadow-sm" style="max-width: 420px; width: 100%">
            <div class="card-body p-4">
                <h1 class="h4 mb-1"><i class="bi bi-key text-primary"></i> Esqueci minha senha</h1>
                <p class="text-muted mb-4">Informe seu e-mail. Vamos mandar um link para você criar uma senha nova.</p>

                <Transition name="alert">
                    <div v-if="sent" class="alert alert-success small d-flex gap-2">
                        <i class="bi bi-envelope-check-fill"></i><span>{{ sent }}</span>
                    </div>
                </Transition>

                <form @submit.prevent="submit">
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input v-model="form.email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.email }" autocomplete="username" autofocus required />
                        <div class="invalid-feedback">{{ form.errors.email }}</div>
                    </div>
                    <button class="btn btn-primary w-100" :disabled="form.processing">Enviar link</button>
                </form>
            </div>
            <div class="card-footer bg-white text-center small">
                <Link href="/login">Voltar para o login</Link>
            </div>
        </div>
    </div>
</template>
