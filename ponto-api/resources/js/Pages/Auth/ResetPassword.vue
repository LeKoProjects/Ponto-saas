<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ token: String, email: String });
const form = useForm({ token: props.token, email: props.email ?? '', password: '', password_confirmation: '' });
const submit = () => form.post('/redefinir-senha', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Criar nova senha" />
    <div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
        <div class="card shadow-sm" style="max-width: 420px; width: 100%">
            <div class="card-body p-4">
                <h1 class="h4 mb-1"><i class="bi bi-shield-lock text-primary"></i> Criar nova senha</h1>
                <p class="text-muted mb-4">Use pelo menos 8 caracteres, com letras e números.</p>

                <form @submit.prevent="submit">
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input v-model="form.email" type="email" class="form-control" :class="{ 'is-invalid': form.errors.email }" autocomplete="username" required />
                        <div class="invalid-feedback">{{ form.errors.email }}</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nova senha</label>
                        <input v-model="form.password" type="password" class="form-control" :class="{ 'is-invalid': form.errors.password }" autocomplete="new-password" required autofocus />
                        <div class="invalid-feedback">{{ form.errors.password }}</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Repita a nova senha</label>
                        <input v-model="form.password_confirmation" type="password" class="form-control" autocomplete="new-password" required />
                    </div>
                    <button class="btn btn-primary w-100" :disabled="form.processing">Salvar nova senha</button>
                </form>
            </div>
            <div class="card-footer bg-white text-center small">
                <Link href="/login">Voltar para o login</Link>
            </div>
        </div>
    </div>
</template>
