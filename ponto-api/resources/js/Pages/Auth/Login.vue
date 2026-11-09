<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const status = usePage().props.flash?.success;
const form = useForm({ email: '', password: '', remember: false });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Entrar" />
    <div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
        <div class="card shadow-sm" style="max-width: 400px; width: 100%">
            <div class="card-body p-4">
                <h1 class="h4 mb-1"><i class="bi bi-fingerprint text-primary"></i> Ponto SaaS</h1>
                <p class="text-muted mb-4">Painel do RH e gestores</p>
                <div v-if="status" class="alert alert-success small">{{ status }}</div>
                <form @submit.prevent="submit">
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input v-model="form.email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.email }" autofocus required />
                        <div class="invalid-feedback">{{ form.errors.email }}</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Senha</label>
                        <input v-model="form.password" type="password" class="form-control" required />
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="form-check mb-0">
                            <input id="remember" v-model="form.remember" type="checkbox" class="form-check-input" />
                            <label for="remember" class="form-check-label">Manter conectado</label>
                        </div>
                        <Link href="/esqueci-senha" class="small">Esqueci minha senha</Link>
                    </div>
                    <button class="btn btn-primary w-100" :disabled="form.processing">Entrar</button>
                </form>
            </div>
            <div class="card-footer bg-body text-center small">
                <a href="/privacidade" target="_blank" class="text-muted">Política de privacidade</a>
            </div>
        </div>
    </div>
</template>
