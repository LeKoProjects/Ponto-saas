<script setup>
import { computed, onMounted, ref } from 'vue';
import { download, post } from '../api';

const WEEK = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const ACTIONS = {
    inclusao: { label: 'Adicionar', title: 'Adicionar batida', icon: 'bi-plus-circle', help: 'Esqueceu de bater? Informe o horário em que realmente aconteceu.' },
    desconsideracao: { label: 'Excluir', title: 'Excluir batida', icon: 'bi-trash3', help: 'Batida registrada por engano ou em duplicidade.' },
    alteracao: { label: 'Alterar', title: 'Alterar batida', icon: 'bi-pencil-square', help: 'Bateu em horário diferente do real? Informe o horário correto.' },
};
const STATUS_CLS = { pendente: 'warning', aprovado: 'success', rejeitado: 'danger' };

const pad = (n) => String(n).padStart(2, '0');
const todayStr = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Bahia' }); // YYYY-MM-DD
const currentMonth = todayStr.slice(0, 7);

const month = ref(currentMonth);
const report = ref(null);
const selected = ref(todayStr);
const showCalendar = ref(true);
const loading = ref(false);
const error = ref('');
const notice = ref('');

const fmt = (m) => {
    const s = m < 0 ? '-' : '';
    const a = Math.abs(m || 0);
    return `${s}${pad(Math.floor(a / 60))}:${pad(a % 60)}`;
};
const brDate = (iso) => iso.split('-').reverse().join('/');

const monthLabel = computed(() => {
    const [y, m] = month.value.split('-').map(Number);
    return `${MONTHS[m - 1]} - ${y}`;
});
const daysByDate = computed(() => Object.fromEntries((report.value?.days ?? []).map((d) => [d.date, d])));
const day = computed(() => daysByDate.value[selected.value] ?? null);

/** Grade do calendário: semanas completas, com dias do mês anterior/seguinte esmaecidos. */
const cells = computed(() => {
    const [y, m] = month.value.split('-').map(Number);
    const first = new Date(y, m - 1, 1).getDay();
    const total = new Date(y, m, 0).getDate();
    const prevTotal = new Date(y, m - 1, 0).getDate();
    const out = [];
    for (let i = first - 1; i >= 0; i--) out.push({ n: prevTotal - i, outside: true });
    for (let n = 1; n <= total; n++) {
        const date = `${y}-${pad(m)}-${pad(n)}`;
        out.push({ n, date, info: daysByDate.value[date] });
    }
    for (let n = 1; out.length % 7; n++) out.push({ n, outside: true });
    return out;
});

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        report.value = await post('/historico', { month: month.value });
        if (!selected.value.startsWith(month.value)) {
            selected.value = month.value === currentMonth ? todayStr : `${month.value}-01`;
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};

const shiftMonth = (delta) => {
    const [y, m] = month.value.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const next = `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
    if (next > currentMonth) return;
    month.value = next;
    selected.value = '';
    load();
};

const downloading = ref(false);
const downloadPdf = async () => {
    downloading.value = true;
    try {
        await download('/espelho/pdf', { month: month.value }, `espelho-${month.value}.pdf`);
    } catch (e) {
        error.value = e.message;
    } finally {
        downloading.value = false;
    }
};

// ---- Solicitação de ajuste ----
const lockedEntries = computed(() =>
    new Set((day.value?.requests ?? []).filter((r) => r.status !== 'rejeitado' && r.time_entry_id).map((r) => r.time_entry_id)),
);
const editableMarks = computed(() =>
    (day.value?.marks ?? []).filter((m) => m.source === 'original' && !m.discarded && !lockedEntries.value.has(m.id)),
);
const canRequest = computed(() => day.value && !day.value.is_future);
const canUse = (type) => canRequest.value && (type === 'inclusao' || editableMarks.value.length > 0);

const form = ref(null);
const sending = ref(false);
const formError = ref('');

const openForm = (type) => {
    if (!canUse(type)) return;
    formError.value = '';
    form.value = { type, time: '', time_entry_id: editableMarks.value[0]?.id ?? null, reason: '' };
};

const submit = async () => {
    sending.value = true;
    formError.value = '';
    try {
        const body = { type: form.value.type, reason: form.value.reason };
        if (form.value.type === 'inclusao') body.date = selected.value;
        if (form.value.type !== 'inclusao') body.time_entry_id = form.value.time_entry_id;
        if (form.value.type !== 'desconsideracao') body.time = form.value.time;
        const res = await post('/ajustes', body);
        form.value = null;
        notice.value = res.message;
        setTimeout(() => (notice.value = ''), 6000);
        await load();
    } catch (e) {
        formError.value = e.message;
    } finally {
        sending.value = false;
    }
};

onMounted(load);
</script>

<template>
    <div class="pt-3">
        <h1 class="h5 fw-semibold mb-3">Espelho detalhado</h1>

        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div class="min-w-0">
                <small class="text-muted">Competência</small>
                <div class="d-flex align-items-center text-nowrap">
                    <button class="btn btn-sm btn-link px-1" aria-label="Mês anterior" @click="shiftMonth(-1)"><i class="bi bi-chevron-left"></i></button>
                    <span class="fw-semibold" style="font-size: 1.1rem">{{ monthLabel }}</span>
                    <button class="btn btn-sm btn-link px-1" aria-label="Próximo mês" :disabled="month >= currentMonth" @click="shiftMonth(1)"><i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
            <button class="btn btn-outline-primary btn-sm fw-semibold text-nowrap flex-shrink-0" :disabled="downloading || !report" @click="downloadPdf">
                <span v-if="downloading" class="spinner-border spinner-border-sm"></span>
                <template v-else><i class="bi bi-download"></i> Baixar espelho</template>
            </button>
        </div>

        <Transition name="alert">
            <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
        </Transition>
        <Transition name="alert">
            <div v-if="notice" class="alert alert-success py-2"><i class="bi bi-check-circle-fill"></i> {{ notice }}</div>
        </Transition>

        <!-- Calendário -->
        <Transition name="collapse">
        <div v-show="showCalendar" class="calendar mb-1" :class="{ loading }">
            <div class="cal-grid cal-head">
                <div v-for="(w, i) in WEEK" :key="i">{{ w }}</div>
            </div>
            <div class="cal-grid">
                <button
                    v-for="(c, i) in cells"
                    :key="i"
                    class="cal-day"
                    :class="{ outside: c.outside, selected: c.date === selected, today: c.date === todayStr, off: c.info && !c.info.is_workday }"
                    :disabled="c.outside"
                    @click="selected = c.date"
                >
                    <span>{{ String(c.n).padStart(2, '0') }}</span>
                    <span class="cal-marks">
                        <i v-if="c.info?.holiday" class="bi bi-star-fill text-primary"></i>
                        <i v-else-if="c.info?.requests.some((r) => r.status === 'pendente')" class="dot bg-warning"></i>
                        <i v-else-if="c.info?.absent" class="dot bg-danger"></i>
                        <i v-else-if="c.info?.incomplete" class="dot bg-secondary"></i>
                    </span>
                </button>
            </div>
            <div class="text-center small text-muted mt-2">
                <b>Escala:</b> {{ report?.employee.schedule }} <span v-if="report">({{ report.employee.schedule_hours }})</span>
            </div>
            <div class="d-flex justify-content-center gap-3 small text-muted mt-1">
                <span><i class="bi bi-star-fill text-primary"></i> feriado</span>
                <span><i class="dot bg-warning"></i> ajuste pendente</span>
                <span><i class="dot bg-danger"></i> falta</span>
            </div>
        </div>
        </Transition>
        <div class="text-center">
            <button class="btn btn-link text-secondary" :aria-label="showCalendar ? 'Recolher calendário' : 'Mostrar calendário'" @click="showCalendar = !showCalendar">
                <i class="bi bi-chevron-up chevron" :class="{ flipped: !showCalendar }"></i>
            </button>
        </div>

        <!-- Dia selecionado -->
        <div class="divider-date">
            <Transition name="fade" mode="out-in">
                <span :key="selected" class="badge text-bg-primary fs-6 px-3 py-2">{{ selected ? brDate(selected) : '—' }}</span>
            </Transition>
        </div>

        <Transition name="day" mode="out-in">
        <div :key="selected + (report ? 'r' : '')">
        <div v-if="!report" class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary"></span></div>
        <div v-else-if="!day" class="text-center text-muted py-3">Sem dados para este dia.</div>
        <template v-else>
            <div v-if="day.holiday" class="text-center mb-2"><span class="badge text-bg-info">{{ day.holiday }}</span></div>

            <ul v-if="day.marks.length" class="list-group list-group-flush mb-3">
                <li v-for="(m, i) in day.marks" :key="i" class="list-group-item d-flex justify-content-between align-items-center px-1">
                    <span :class="{ 'text-decoration-line-through text-danger': m.discarded, 'text-success fw-semibold': m.source !== 'original' }">
                        <b class="fs-5">{{ m.time }}</b>
                        <span class="small ms-2">{{ m.type_label }}</span>
                    </span>
                    <small class="text-muted">{{ m.nsr ? `NSR ${m.nsr}` : '' }}{{ m.discarded ? ' · excluída' : '' }}</small>
                </li>
            </ul>
            <p v-else class="text-center text-muted fs-5 my-3">Não há batidas</p>

            <dl class="row small mb-2 mx-0">
                <dt class="col-7 text-muted fw-normal">Horas previstas:</dt><dd class="col-5 mb-1">{{ fmt(day.expected_minutes) }}</dd>
                <dt class="col-7 text-muted fw-normal">Horas previstas com intervalo:</dt><dd class="col-5 mb-1">{{ fmt(day.expected_with_break_minutes) }}</dd>
                <dt class="col-7 text-muted fw-normal">Horas trabalhadas:</dt><dd class="col-5 mb-1">{{ fmt(day.worked_minutes) }}</dd>
                <template v-if="day.late_minutes">
                    <dt class="col-7 text-muted fw-normal">Atraso:</dt><dd class="col-5 mb-1 text-danger">{{ fmt(day.late_minutes) }}</dd>
                </template>
                <template v-if="day.overtime_50_minutes + day.overtime_100_minutes">
                    <dt class="col-7 text-muted fw-normal">Horas extras:</dt><dd class="col-5 mb-1 text-success">{{ fmt(day.overtime_50_minutes + day.overtime_100_minutes) }}</dd>
                </template>
                <template v-if="day.missing_minutes">
                    <dt class="col-7 text-muted fw-normal">Horas faltantes:</dt><dd class="col-5 mb-1 text-danger">{{ fmt(day.missing_minutes) }}</dd>
                </template>
            </dl>

            <div v-if="day.requests.length" class="mb-3">
                <div class="small fw-semibold text-muted mb-1">Minhas solicitações neste dia</div>
                <div v-for="r in day.requests" :key="r.id" class="card card-body py-2 px-3 mb-2 small">
                    <div class="d-flex justify-content-between">
                        <b>{{ r.summary }}</b>
                        <span :class="`badge text-bg-${STATUS_CLS[r.status]}`">{{ r.status_label }}</span>
                    </div>
                    <div class="text-muted">{{ r.reason }}</div>
                    <div v-if="r.decision_note" class="fst-italic">RH: "{{ r.decision_note }}"</div>
                </div>
            </div>

            <!-- Solicitar ajuste -->
            <div class="border-top pt-3">
                <h2 class="h6 fw-semibold mb-3">Solicitar ajuste de ponto</h2>
                <div class="d-flex justify-content-around">
                    <button v-for="(a, type) in ACTIONS" :key="type" class="action-btn" :disabled="!canUse(type)" @click="openForm(type)">
                        <span class="action-icon"><i :class="`bi ${a.icon}`"></i></span>
                        <span class="small">{{ a.label }}</span>
                    </button>
                </div>
                <p v-if="day.is_future" class="small text-muted text-center mt-2 mb-0">Não é possível ajustar dias futuros.</p>
            </div>
        </template>
        </div>
        </Transition>

        <!-- Formulário (bottom sheet) -->
        <Teleport to="body">
        <Transition name="sheet">
        <div v-if="form" class="sheet-backdrop" @click.self="form = null">
            <form class="sheet" @submit.prevent="submit">
                <div class="sheet-handle"></div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h5 mb-0">{{ ACTIONS[form.type].title }}</h3>
                    <button type="button" class="btn-close" aria-label="Fechar" @click="form = null"></button>
                </div>
                <p class="small text-muted mb-3">{{ brDate(selected) }} · {{ ACTIONS[form.type].help }}</p>

                <Transition name="alert">
                    <div v-if="formError" class="alert alert-danger py-2 small">{{ formError }}</div>
                </Transition>

                <div v-if="form.type !== 'inclusao'" class="mb-3">
                    <label class="form-label small fw-semibold">Qual batida?</label>
                    <div class="d-flex flex-wrap gap-2">
                        <template v-for="m in editableMarks" :key="m.id">
                            <input :id="`m${m.id}`" v-model="form.time_entry_id" type="radio" class="btn-check" :value="m.id" />
                            <label class="btn btn-outline-primary" :for="`m${m.id}`">{{ m.time }}</label>
                        </template>
                    </div>
                </div>

                <div v-if="form.type !== 'desconsideracao'" class="mb-3">
                    <label class="form-label small fw-semibold">{{ form.type === 'alteracao' ? 'Horário correto' : 'Horário' }}</label>
                    <input v-model="form.time" type="time" class="form-control form-control-lg" required />
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Justificativa</label>
                    <textarea v-model="form.reason" class="form-control" rows="3" minlength="5" maxlength="500" placeholder="Ex.: esqueci de registrar a saída" required></textarea>
                </div>

                <p class="small text-muted">A batida original não é apagada. O ajuste só vale depois de aprovado pelo RH ou pelo seu gestor.</p>
                <button class="btn btn-primary btn-lg w-100" :disabled="sending">
                    <span v-if="sending" class="spinner-border spinner-border-sm me-2"></span>Enviar solicitação
                </button>
            </form>
        </div>
        </Transition>
        </Teleport>
    </div>
</template>

<style scoped>
.cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    text-align: center;
}
.cal-head div {
    font-weight: 600;
    color: #6c757d;
    padding: 6px 0;
}
.calendar {
    transition: opacity var(--base) var(--ease);
}
.calendar.loading {
    opacity: 0.5;
}
.cal-day {
    border: 0;
    background: none;
    border-radius: 12px;
    padding: 8px 0 2px;
    margin: 2px;
    font-size: 1.05rem;
    color: #343a40;
    display: flex;
    flex-direction: column;
    align-items: center;
    transition:
        background-color var(--base) var(--ease),
        color var(--base) var(--ease),
        transform var(--fast) var(--ease),
        box-shadow var(--base) var(--ease);
}
.cal-day:active:not(:disabled) {
    transform: scale(0.9);
}
.cal-day.off {
    color: #8a929a;
}
.cal-day.outside {
    color: #ced4da;
}
.cal-day.today {
    font-weight: 700;
}
.cal-day.selected {
    background: var(--bs-primary);
    color: #fff;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
}
.cal-day.selected .text-primary {
    color: #fff !important;
}
.cal-marks {
    height: 14px;
    font-size: 0.6rem;
    line-height: 14px;
}
.dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    vertical-align: middle;
}
.chevron {
    display: inline-block;
    transition: transform var(--base) var(--ease);
}
.chevron.flipped {
    transform: rotate(180deg);
}
.divider-date {
    text-align: center;
    border-top: 1px solid #dee2e6;
    margin: 8px 0 16px;
    line-height: 0;
}
.action-btn {
    border: 0;
    background: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    color: #343a40;
    transition: opacity var(--base) var(--ease);
}
.action-btn:disabled {
    opacity: 0.35;
}
.action-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    transition:
        transform var(--fast) var(--ease),
        background-color var(--base) var(--ease),
        color var(--base) var(--ease);
}
.action-btn:not(:disabled):hover .action-icon {
    background: #dbe7ff;
    color: var(--bs-primary);
}
.action-btn:not(:disabled):active .action-icon {
    transform: scale(0.9);
}

/* Troca de dia: conteúdo esmaece e sobe levemente */
.day-enter-active,
.day-leave-active {
    transition:
        opacity var(--fast) var(--ease),
        transform var(--base) var(--ease);
}
.day-enter-from {
    opacity: 0;
    transform: translateY(8px);
}
.day-leave-to {
    opacity: 0;
}

/* Recolher/expandir calendário */
.collapse-enter-active,
.collapse-leave-active {
    transition:
        opacity var(--base) var(--ease),
        transform var(--base) var(--ease);
    transform-origin: top;
}
.collapse-enter-from,
.collapse-leave-to {
    opacity: 0;
    transform: scaleY(0.96) translateY(-8px);
}
</style>
