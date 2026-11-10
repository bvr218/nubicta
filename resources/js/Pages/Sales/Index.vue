<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';
import RowMenu from '../../Components/RowMenu.vue';

const props = defineProps({
    documents: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    sellers: { type: Array, default: () => [] },
});

const f = reactive({ q: '', tipo: '', desde: '', hasta: '', saldo: '', vendedor: '', ...props.filters });
const showFilters = ref(!!(f.tipo || f.desde || f.hasta || f.saldo || f.vendedor));
function reload() {
    router.get('/ventas', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(reload, 350);
}
const money = (v, c) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + c;
const dian = (s) => ({ Accepted: ['Aprobada', 'text-ok', 'circle-check'], Rejected: ['Rechazada', 'text-danger', 'triangle-exclamation'] })[s] ?? [s ? 'Pendiente' : 'Sin enviar', 'text-muted', 'clock-rotate-left'];
const mail = (s) => ({
    sent: ['Enviado', 's-badge-ok'], read: ['Leído', 's-badge-ok'], queued: ['En cola', 's-badge-off'], not_sent: ['Sin enviar', 's-badge-warn'],
    no_email: ['Sin correo', 's-badge-warn'], blocked: ['Correo bloqueado', 's-badge-danger'], failed: ['Fallo de envío', 's-badge-danger'],
    failed_delivery: ['Fallo de entrega', 's-badge-danger'], error: ['Fallo de envío', 's-badge-danger'],
})[s] ?? ['Sin enviar', 's-badge-warn'];
const pagination = computed(() => props.documents);

const menu = ref(null);
const REPORTS = [['Documentos de venta', '/reportes/movimiento-facturas-venta'], ['Comprobante informe diario', '/contabilidad/libro-diario'],
    ['Comparativo de ventas por mes', '/reportes/comparativo-ventas-mes'], ['Ventas por cliente', '/reportes/ventas-cliente'],
    ['Ventas por vendedor', '/reportes/ventas-vendedor'], ['Ventas por producto', '/reportes/ventas-producto'], ['Ver más reportes', '/reportes']];
const sending = ref(null);
const to = ref('');
const docUrl = (d) => d.public_url || `/documentos/ventas/${d.id}/imprimir`;
const canSend = (d) => !d.annulled && !['sent', 'read'].includes(d.mail_status);
function openSend(d) {
    menu.value = null;
    sending.value = d;
    to.value = d.email || '';
}
function send() {
    router.post(`/documentos/ventas/${sending.value.id}/correo`, { to: to.value }, { preserveScroll: true, onSuccess: () => (sending.value = null) });
}
</script>

<template>
    <Head title="Ventas" />
    <PageHeader title="Ventas" :tabs="tabs" active="Ventas" />

    <section class="s-card mt-[18px]" @click="menu = null">
        <div class="flex flex-wrap items-center gap-3 px-[16px] py-[16px]">
            <div class="relative w-full sm:w-[300px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="f.q" class="s-input pl-9" placeholder="Buscar por Identificación o Cliente" @input="search" />
            </div>
            <button type="button" class="s-btn s-btn-outline" @click="showFilters = !showFilters"><Icon icon="sliders" /> Más filtros</button>
            <div class="ml-auto flex flex-wrap justify-end gap-2">
                <div class="relative" @click.stop>
                    <button type="button" class="s-btn s-btn-outline" @click="menu = menu === 'rep' ? null : 'rep'">Ver reportes <Icon icon="caret-down" /></button>
                    <div v-if="menu === 'rep'" class="absolute right-0 top-[40px] z-30 w-[240px] rounded-[6px] bg-white py-[6px] shadow-lg">
                        <Link v-for="[l, u] in REPORTS" :key="l" :href="u" class="block px-[14px] py-[8px] text-[13px] text-ink hover:bg-[#f0f9ff]">{{ l }}</Link>
                    </div>
                </div>
                <div class="relative inline-flex" @click.stop>
                    <Link href="/ventas/facturas/crear" class="s-btn s-btn-primary w-[150px] rounded-r-none">Crear factura</Link>
                    <button type="button" class="s-btn s-btn-primary rounded-l-none border-l border-white/40 px-[12px]" aria-label="Más opciones" @click="menu = menu === 'crear' ? null : 'crear'"><Icon icon="caret-down" /></button>
                    <div v-if="menu === 'crear'" class="absolute right-0 top-[40px] z-30 w-[190px] rounded-[6px] bg-white py-[6px] shadow-lg">
                        <Link href="/ventas/facturas-recurrentes/crear" class="block px-[14px] py-[8px] text-[13px] text-ink hover:bg-[#f0f9ff]">Factura recurrente</Link>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="showFilters" class="flex flex-wrap items-end gap-3 px-[16px] pb-[16px]">
            <label><span class="s-label">Tipo</span>
                <select v-model="f.tipo" class="s-input w-[160px]" @change="reload">
                    <option value="">Todos</option><option value="FV">Facturas de venta</option><option value="NC">Notas crédito</option><option value="ND">Notas débito</option>
                </select>
            </label>
            <label><span class="s-label">Desde</span><input v-model="f.desde" type="date" class="s-input w-[150px]" @change="reload" /></label>
            <label><span class="s-label">Hasta</span><input v-model="f.hasta" type="date" class="s-input w-[150px]" @change="reload" /></label>
            <label><span class="s-label">Saldo</span>
                <select v-model="f.saldo" class="s-input w-[150px]" @change="reload"><option value="">Todos</option><option value="pendiente">Con saldo pendiente</option></select>
            </label>
            <label><span class="s-label">Vendedor</span>
                <select v-model="f.vendedor" class="s-input w-[180px]" @change="reload"><option value="">Todos</option><option v-for="s in sellers" :key="s.id" :value="String(s.id)">{{ s.name }}</option></select>
            </label>
        </div>

        <div class="s-table-wrap">
            <table class="sticky-actions s-table">
                <thead>
                    <tr>
                        <th>Fecha</th><th>Comprobante</th><th>Cliente</th><th class="text-right">Total</th><th class="text-right">Saldo</th>
                        <th>DIAN</th><th>Correo</th><th class="w-[160px]">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in documents.data" :key="d.id" :class="{ 'opacity-60': d.annulled }">
                        <td class="whitespace-nowrap">{{ d.date }}</td>
                        <td><Link :href="`/documentos/ventas/${d.id}`" class="text-primary">{{ d.name }}</Link><div class="text-[11px] text-muted">{{ d.type }}<span v-if="d.annulled"> · Anulada</span></div></td>
                        <td class="max-w-[240px]"><div class="truncate text-primary">{{ d.customer }}</div><div class="text-[11px] text-muted">{{ d.identification }}</div></td>
                        <td class="whitespace-nowrap text-right">{{ money(d.total, d.currency) }}</td>
                        <td class="text-right">
                            <div>{{ money(d.balance, '').trim() }}</div>
                            <span v-if="d.is_invoice" class="s-badge" :class="d.balance > 0 ? 's-badge-warn' : 's-badge-ok'">{{ d.balance > 0 ? 'Pendiente' : 'Pagada' }}</span>
                        </td>
                        <td class="whitespace-nowrap" :class="dian(d.dian_status)[1]"><Icon :icon="dian(d.dian_status)[2]" /> <span class="text-ink">{{ dian(d.dian_status)[0] }}</span></td>
                        <td><span class="s-badge" :class="mail(d.mail_status)[1]">{{ mail(d.mail_status)[0] }}</span></td>
                        <td>
                            <RowMenu v-if="canSend(d)" :open="menu === d.id" anchor-class="flex" @close="menu = null">
                                <template #anchor>
                                    <button type="button" class="s-btn s-btn-outline h-[28px] flex-1 rounded-r-none text-[12px]" @click="openSend(d)">Enviar por correo</button>
                                    <button type="button" class="s-btn s-btn-outline h-[28px] rounded-l-none border-l-0 px-2" @click="menu = menu === d.id ? null : d.id"><Icon icon="caret-down" /></button>
                                </template>
                                <a :href="docUrl(d)" target="_blank" rel="noopener" class="block px-3 py-2 text-[13px] hover:bg-[#f5f6f7]">Ver documento</a>
                                <Link v-if="d.is_invoice && d.balance > 0" :href="`/ventas/clientes/${d.contact_id}`" class="block px-3 py-2 text-[13px] hover:bg-[#f5f6f7]">Ver saldo</Link>
                            </RowMenu>
                            <a v-else :href="docUrl(d)" target="_blank" rel="noopener" class="s-btn s-btn-outline h-[28px] w-full text-[12px]">Ver documento</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!documents.data.length" />
        <Pager :paginator="pagination" />
    </section>

    <div v-if="sending" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3" @click.self="sending = null">
        <form class="s-card w-[420px] max-w-full p-[20px]" @submit.prevent="send">
            <div class="text-[16px] font-bold text-ink">Enviar {{ sending.name }} por correo</div>
            <label class="mt-[12px] block text-[12px] text-muted">Correo del cliente
                <input v-model="to" type="email" required class="s-input mt-[4px] w-full" />
            </label>
            <div class="mt-[16px] flex justify-end gap-2">
                <button type="button" class="s-btn s-btn-cancel" @click="sending = null">Cancelar</button>
                <button class="s-btn s-btn-primary">Enviar</button>
            </div>
        </form>
    </div>
</template>
