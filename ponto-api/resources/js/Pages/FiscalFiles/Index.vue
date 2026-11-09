<script setup>
import { computed, reactive } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps({ certificate: Object, certificate_error: String, inpi: String, developer: String, defaults: Object });
const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const form = reactive({ from: props.defaults.from, to: props.defaults.to });
// Download direto pelo navegador (o arquivo não passa pelo Inertia).
const afdUrl = computed(() => `/arquivos-fiscais/afd?${new URLSearchParams(form)}`);
const pending = computed(() => [!props.inpi && 'número de registro no INPI (PONTO_INPI_REGISTRATION)', !props.developer && 'CNPJ do desenvolvedor (PONTO_DEVELOPER_CNPJ)'].filter(Boolean));
</script>

<template>
    <Head title="Arquivos fiscais" />
    <h1 class="h3 mb-1">Arquivos fiscais</h1>
    <p class="text-muted mb-3">Arquivos oficiais da Portaria MTP nº 671/2021 para entregar à fiscalização do trabalho ou à Justiça.</p>

    <div class="row g-3" style="max-width: 1100px">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text"></i> AFD — Arquivo Fonte de Dados</div>
                <div class="card-body">
                    <p class="small text-muted">
                        Todas as marcações do período, com NSR, CPF, data/hora e hash encadeado, no leiaute oficial (versão 003).
                        Vem em um <code>.zip</code> com o <code>.txt</code> e a assinatura digital destacada <code>.p7s</code>.
                    </p>
                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label">De</label>
                            <input v-model="form.from" type="date" class="form-control" :class="{ 'is-invalid': errors.from }" />
                            <div class="invalid-feedback">{{ errors.from }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Até</label>
                            <input v-model="form.to" type="date" class="form-control" :class="{ 'is-invalid': errors.to }" />
                            <div class="invalid-feedback">{{ errors.to }}</div>
                        </div>
                    </div>
                    <a :href="afdUrl" class="btn btn-primary"><i class="bi bi-download"></i> Baixar AFD assinado</a>
                </div>
            </div>

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
                    <div v-if="certificate_error" class="alert alert-danger mb-0">{{ certificate_error }}</div>
                    <template v-else-if="certificate">
                        <div class="mb-2">
                            <span v-if="certificate.icp_brasil" class="badge text-bg-success">ICP-Brasil</span>
                            <span v-else class="badge text-bg-warning">Certificado de TESTE: sem valor legal</span>
                        </div>
                        <div><b>Titular:</b> {{ certificate.subject }}</div>
                        <div><b>Emissor:</b> {{ certificate.issuer }}</div>
                        <div><b>Válido até:</b> {{ certificate.valid_to }}</div>
                        <p class="text-muted mt-2 mb-0">Assinatura CAdES destacada (.p7s) com o certificado A1 do desenvolvedor do REP-P.</p>
                    </template>
                    <div v-else class="alert alert-warning mb-0">
                        Nenhum certificado configurado: o AFD sai <b>sem assinatura</b>. Peça ao suporte do sistema para instalar o certificado A1 ICP-Brasil.
                    </div>

                    <div v-if="pending.length" class="alert alert-secondary mt-3 mb-0">
                        Falta configurar no servidor: {{ pending.join(' e ') }}. Sem isso, o cabeçalho do AFD sai com esses campos zerados.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
