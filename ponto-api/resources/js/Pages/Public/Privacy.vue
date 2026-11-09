<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({ operator: Object, version: Object, company: Object });

const effective = computed(() => new Date(`${props.version.effective_at}T12:00:00`).toLocaleDateString('pt-BR'));
const controller = computed(() => props.company?.name ?? 'a empresa empregadora que contratou o serviço');
const contactEmail = computed(() => props.company?.privacy_email || props.operator.email);

const sections = [
    { id: 'quem', title: 'Quem trata os seus dados' },
    { id: 'dados', title: 'Quais dados coletamos' },
    { id: 'finalidade', title: 'Para que usamos e com qual base legal' },
    { id: 'localizacao', title: 'Localização (GPS)' },
    { id: 'aparelho', title: 'Aparelho autorizado' },
    { id: 'compartilhamento', title: 'Com quem compartilhamos' },
    { id: 'retencao', title: 'Por quanto tempo guardamos' },
    { id: 'seguranca', title: 'Como protegemos' },
    { id: 'direitos', title: 'Seus direitos' },
    { id: 'armazenamento', title: 'Armazenamento no aparelho' },
    { id: 'contato', title: 'Contato e alterações' },
];
</script>

<template>
    <Head title="Política de privacidade" />
    <div class="privacy container py-4 py-lg-5">
        <header class="d-flex align-items-center gap-3 mb-4">
            <img v-if="company?.logo_url" :src="company.logo_url" alt="" class="logo-preview" style="width: 72px; height: 56px" />
            <div>
                <h1 class="h3 mb-0">Política de privacidade</h1>
                <div class="text-muted small">
                    Registro de ponto eletrônico · versão {{ version.version }} · vigente desde {{ effective }}
                </div>
            </div>
        </header>

        <div class="row g-4">
            <nav class="col-lg-3 d-none d-lg-block">
                <div class="position-sticky" style="top: 1.5rem">
                    <div class="small text-uppercase text-muted fw-semibold mb-2">Nesta página</div>
                    <a v-for="s in sections" :key="s.id" :href="`#${s.id}`" class="d-block small py-1 text-decoration-none">{{ s.title }}</a>
                </div>
            </nav>

            <article class="col-lg-9">
                <div class="alert alert-light border small">
                    <b>Resumo:</b> usamos seus dados para registrar a jornada de trabalho, como manda a lei (Portaria MTP nº 671/2021).
                    As marcações de ponto não podem ser apagadas nem alteradas, só corrigidas por ajuste justificado.
                    A localização só é coletada se a sua empresa ativar, e o app avisa. Você pode pedir acesso e correção dos seus dados a qualquer momento.
                </div>

                <section id="quem">
                    <h2>1. Quem trata os seus dados</h2>
                    <p>
                        <b>Controlador:</b> {{ controller }}<template v-if="company"> (CNPJ {{ company.cnpj }})</template>, que é o seu empregador e decide
                        como o controle de jornada é feito.
                    </p>
                    <p>
                        <b>Operador:</b> {{ operator.name }}<template v-if="operator.cnpj"> (CNPJ {{ operator.cnpj }})</template>, fornecedor do sistema de ponto,
                        que trata os dados somente conforme as instruções do empregador e esta política.
                    </p>
                </section>

                <section id="dados">
                    <h2>2. Quais dados coletamos</h2>
                    <ul>
                        <li><b>Cadastro:</b> nome, e-mail, CPF, matrícula, data de admissão, equipe e jornada (informados pelo RH).</li>
                        <li><b>Marcações de ponto:</b> data e hora (sempre do servidor), tipo da marcação, número sequencial (NSR) e código de integridade (hash).</li>
                        <li><b>Ajustes:</b> solicitações de inclusão, alteração ou desconsideração de marcação, com a justificativa e a decisão do RH.</li>
                        <li><b>Aparelho:</b> identificador gerado pelo app, nome/modelo do aparelho, navegador, endereço IP e datas de acesso.</li>
                        <li><b>Localização:</b> latitude e longitude no momento da batida, <b>somente</b> se a empresa exigir (seção 4).</li>
                        <li><b>Registros de auditoria:</b> quem fez cada ação no sistema e quando.</li>
                    </ul>
                    <p>Não coletamos fotos, biometria, contatos, nem a sua localização fora do momento da batida.</p>
                </section>

                <section id="finalidade">
                    <h2>3. Para que usamos e com qual base legal</h2>
                    <div class="table-responsive">
                        <table class="table table-sm small">
                            <thead><tr><th>Finalidade</th><th>Base legal (LGPD)</th></tr></thead>
                            <tbody>
                                <tr><td>Registrar e guardar a jornada, gerar espelho de ponto, comprovante e arquivos fiscais (AFD/AEJ)</td><td>Cumprimento de obrigação legal (art. 7º, II): CLT art. 74 e Portaria 671/2021</td></tr>
                                <tr><td>Calcular horas extras, atrasos, faltas e adicional noturno para a folha</td><td>Execução do contrato de trabalho (art. 7º, V) e obrigação legal</td></tr>
                                <tr><td>Enviar o comprovante de cada marcação por e-mail</td><td>Obrigação legal (Portaria 671/2021)</td></tr>
                                <tr><td>Vincular o acesso a um único aparelho, auditoria e prevenção a fraudes</td><td>Legítimo interesse (art. 7º, IX) e exercício regular de direitos (art. 7º, VI)</td></tr>
                                <tr><td>Localização na batida, quando ativada</td><td>Legítimo interesse do empregador no controle da jornada externa ou em locais definidos</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p>Seus dados não são usados para publicidade nem vendidos a ninguém.</p>
                </section>

                <section id="localizacao">
                    <h2>4. Localização (GPS)</h2>
                    <p>
                        A coleta de localização é uma configuração do seu empregador. Quando está <b>ativada</b>, o app pede permissão e grava a posição
                        somente no instante da batida. Se a permissão for negada, o ponto não é registrado e você deve procurar o RH.
                        Quando está <b>desativada</b>, o app não pede a localização e o servidor descarta qualquer coordenada recebida.
                        O app não rastreia você em segundo plano.
                    </p>
                </section>

                <section id="aparelho">
                    <h2>5. Aparelho autorizado</h2>
                    <p>
                        Para evitar que outra pessoa bata o ponto por você, o acesso fica limitado a um aparelho liberado pelo RH.
                        Guardamos o histórico dos aparelhos usados (nome, data da liberação ou desconexão e motivo) para auditoria.
                    </p>
                </section>

                <section id="compartilhamento">
                    <h2>6. Com quem compartilhamos</h2>
                    <ul>
                        <li>Seu empregador (RH, dono e o gestor da sua equipe), conforme o perfil de cada um.</li>
                        <li>O contador ou escritório de folha do empregador, quando ele exporta o espelho.</li>
                        <li>Auditores fiscais do trabalho e a Justiça, quando exigido por lei.</li>
                        <li>Fornecedores de infraestrutura (servidor, e-mail e backup) contratados pelo operador, com obrigação de sigilo.</li>
                    </ul>
                </section>

                <section id="retencao">
                    <h2>7. Por quanto tempo guardamos</h2>
                    <p>
                        As marcações, ajustes e arquivos fiscais são guardados pelo prazo exigido pela legislação trabalhista,
                        no mínimo 5 anos após o registro, e podem ser mantidos até o fim de processos judiciais em andamento.
                        Por exigência legal, marcações de ponto não podem ser apagadas antes desse prazo, mesmo a pedido do titular.
                        Dados de aparelho e auditoria seguem o mesmo prazo. Encerrado o prazo, os dados são eliminados ou anonimizados.
                    </p>
                </section>

                <section id="seguranca">
                    <h2>8. Como protegemos</h2>
                    <ul>
                        <li>Conexão criptografada (HTTPS) e senhas armazenadas com hash.</li>
                        <li>Marcações imutáveis no banco de dados, com numeração sequencial e hash encadeado que denuncia qualquer adulteração.</li>
                        <li>Separação dos dados por empresa: uma empresa nunca vê dados de outra.</li>
                        <li>Acesso por perfil, registro de auditoria de todas as ações do RH e backups diários.</li>
                    </ul>
                    <p>Se houver um incidente de segurança relevante, você e a ANPD serão comunicados, como prevê a LGPD.</p>
                </section>

                <section id="direitos">
                    <h2>9. Seus direitos</h2>
                    <p>Pela LGPD (art. 18), você pode pedir:</p>
                    <ul>
                        <li>confirmação de que tratamos seus dados e acesso a eles (o espelho do mês fica disponível no app);</li>
                        <li>correção de dados incompletos ou errados (marcações são corrigidas por <b>solicitação de ajuste</b> no app);</li>
                        <li>informação sobre com quem os dados são compartilhados;</li>
                        <li>anonimização, bloqueio ou eliminação de dados desnecessários, respeitados os prazos legais da seção 7;</li>
                        <li>revisão de decisões e reclamação à Autoridade Nacional de Proteção de Dados (ANPD).</li>
                    </ul>
                </section>

                <section id="armazenamento">
                    <h2>10. Armazenamento no aparelho</h2>
                    <p>
                        O app guarda no seu aparelho apenas o necessário para funcionar: a chave de acesso (token), o identificador do aparelho,
                        seu nome, empresa e jornada e as preferências da tela de configurações. Ao sair da conta, esses dados são apagados,
                        exceto o identificador do aparelho. Não usamos cookies de publicidade nem ferramentas de rastreamento.
                    </p>
                </section>

                <section id="contato">
                    <h2>11. Contato e alterações</h2>
                    <p>
                        Para exercer seus direitos ou tirar dúvidas, fale com o encarregado de dados
                        <template v-if="company?.privacy_email">do seu empregador</template><template v-else>pelo e-mail</template>:
                        <a :href="`mailto:${contactEmail}`">{{ contactEmail }}</a>.
                        Você também pode procurar o RH da sua empresa.
                    </p>
                    <p class="mb-0">
                        Esta política pode ser atualizada. A versão e a data de vigência ficam no topo da página, e mudanças relevantes são avisadas no app.
                    </p>
                </section>
            </article>
        </div>
    </div>
</template>

<style scoped>
.privacy {
    max-width: 1100px;
}
.privacy h2 {
    font-size: 1.15rem;
    margin-top: 1.75rem;
    scroll-margin-top: 1rem;
}
.privacy section:first-of-type h2 {
    margin-top: 0.5rem;
}
</style>
