<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps({ certificate_installed: Boolean, certificate: Object, inpi: String, developer: String, defaults: Object });
const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const form = reactive({ from: props.defaults.from, to: props.defaults.to, password: '' });
const showPassword = ref(false);
const sending = ref(false);

// Envio direto pelo navegador (POST normal, não Inertia) para o .zip baixar.
// A senha vai no corpo da requisição, é usada só para assinar e não fica guardada.
const submit = (e) => {
    sending.value = true;
    e.target.submit();
    setTimeout(() => {
        form.password = '';
        sending.value = false;
    }, 1500);
};

const pending = computed(() => [!props.inpi && 'número de registro no INPI (PONTO_INPI_REGISTRATION)', !props.developer && 'CNPJ do desenvolvedor (PONTO_DEVELOPER_CNPJ)'].filter(Boolean));
</script>

<template>
    <Head title="Arquivos fiscais" />
    <h1 class="h3 mb-1">Arquivos fiscais</h1>
    <p class="text-muted mb-3">Arquivos oficiais da Portaria MTP nº 671/2021 para entregar à fiscalização do trabalho ou à Justiça.</p>

    <div class="row g-3" style="max-width: 1100px">
        <div class="col-lg-7">
            <form class="card border-0 shadow-sm" method="post" action="/arquivos-fiscais/afd" @submit.prevent="submit">
                <input type="hidden" name="_token" :value="page.props.csrf_token" />
                <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text"></i> AFD — Arquivo Fonte de Dados</div>
                <div class="card-body">
                    <p class="small text-muted">
                        Todas as marcações do período, com NSR, CPF, data/hora e hash encadeado, no leiaute oficial (versão 003).
                        Vem em um <code>.zip</code> com o <code>.txt</code> e a assinatura digital destacada <code>.p7s</code>.
                    </p>
                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label">De</label>
                            <input v-model="form.from" name="from" type="date" class="form-control" :class="{ 'is-invalid': errors.from }" required />
                            <div class="invalid-feedback">{{ errors.from }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Até</label>
                            <input v-model="form.to" name="to" type="date" class="form-control" :class="{ 'is-invalid': errors.to }" required />
                            <div class="invalid-feedback">{{ errors.to }}</div>
                        </div>
                    </div>

                    <div v-if="certificate_installed" class="mb-3">
                        <label class="form-label" for="cert-pass"><i class="bi bi-key"></i> Senha do certificado digital</label>
                        <div class="input-group has-validation">
                            <input
                                id="cert-pass"
                                v-model="form.password"
                                name="password"
                                :type="showPassword ? 'text' : 'password'"
                                class="form-control"
                                :class="{ 'is-invalid': errors.password }"
                                autocomplete="off"
                                required
                            />
                            <button type="button" class="btn btn-outline-secondary" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword">
                                <i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                            </button>
                            <div class="invalid-feedback">{{ errors.password }}</div>
                        </div>
                        <div class="form-text">Usada só para assinar este arquivo. A senha não fica salva no sistema.</div>
                    </div>

                    <button class="btn btn-primary" :disabled="sending">
                        <span v-if="sending" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="bi bi-download"></i>
                        {{ certificate_installed ? 'Assinar e baixar AFD' : 'Baixar AFD (sem assinatura)' }}
                    </button>
                </div>
            </form>

            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-hourglass-split"></i> Próximos arquivos</div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item"><b>AEJ</b> (Arquivo Eletrônico de Jornada): jornada contratual, marcações tratadas, ausências e banco de horas, para o eSocial/fiscalização.</li>
                    <li class="list-group-item"><b>Comprovante assinado em PDF</b> (PAdES) a cada marcação.</li>
                    <li class="list-group-item">Registros do AFD de <b>empresa (tipo 2)</b>, <b>empregado (tipo 5)</b> e <b>eventos sensíveis (tipo 6)</b> com NSR próprio.</li>
                </ul>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-patch-check"></i> Assinatura digital</div>
                <div class="card-body small">
                    <template v-if="certificate_installed">
                        <template v-if="certificate">
                            <div class="mb-2">
                                <span v-if="certificate.icp_brasil" class="badge text-bg-success">ICP-Brasil</span>
                                <span v-else class="badge text-bg-warning">Certificado de TESTE: sem valor legal</span>
                            </div>
                            <div><b>Titular:</b> {{ certificate.subject }}</div>
                            <div><b>Emissor:</b> {{ certificate.issuer }}</div>
                            <div><b>Válido até:</b> {{ certificate.valid_to }}</div>
                        </template>
                        <div v-else class="alert alert-info mb-2">
                            Certificado instalado. Titular e validade aparecem aqui depois da primeira assinatura com a senha correta.
                        </div>
                        <p class="text-muted mt-2 mb-0">Assinatura CAdES destacada (.p7s) com o certificado A1 do desenvolvedor do REP-P.</p>
                    </template>
                    <div v-else class="alert alert-warning mb-0">
                        Nenhum certificado instalado: o AFD sai <b>sem assinatura</b>. Peça ao suporte do sistema para instalar o certificado A1 ICP-Brasil.
                    </div>

                    <div v-if="pending.length" class="alert alert-secondary mt-3 mb-0">
                        Falta configurar no servidor: {{ pending.join(' e ') }}. Sem isso, o cabeçalho do AFD sai com esses campos zerados.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
