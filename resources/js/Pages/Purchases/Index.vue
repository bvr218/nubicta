<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    documents: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    last15: { type: Boolean, default: false },
});

const f = reactive({ q: '', tipo: '', desde: '', hasta: '', saldo: '', ...props.filters });
const open = ref(!props.last15 && !!(f.desde || f.hasta || f.saldo || f.q));
const newMenu = ref(false);
function reload() {
    router.get('/compras', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(reload, 350);
}
function clear() {
    Object.assign(f, { q: '', tipo: '', desde: '', hasta: '', saldo: '' });
    router.get('/compras', { todos: 1 }, { preserveState: true, replace: true });
}
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const NEW = [['Factura de Compra / Gasto', '/compras/facturas/crear'], ['Documento soporte', '/compras/documentos-soporte/crear'], ['Orden de Compra', '/compras/ordenes/crear'],
    ['Recibo de Pago / Egreso', '/compras/egresos/crear'], ['Nota débito / Nota de ajuste', '/compras/notas-debito/crear'], ['Ajuste cartera / proveedores', '/contabilidad/ajustes-cartera/crear']];
const TIPOS = { FC: 'Compra/Gasto', DS: 'Documento soporte', RP: 'Pagos' };
</script>

<template>
    <Head title="Compras y gastos" />
    <PageHeader title="Compras y gastos" :tabs="tabs" active="Documentos de compras y gastos" />

    <div class="-mx-3 md:-mx-[22px] flex justify-end bg-[#eef1f4] px-[22px] py-[14px]">
        <div class="relative">
            <button type="button" class="s-btn s-btn-primary" @click="newMenu = !newMenu">Nueva Compra / Gasto / Doc Soporte <Icon icon="chevron-down" /></button>
            <div v-if="newMenu" class="absolute right-0 z-20 mt-1 w-[230px] rounded-[6px] bg-white py-1 shadow-lg">
                <Link v-for="[l, u] in NEW" :key="l" :href="u" class="block px-4 py-2 hover:bg-primary-light">{{ l }}</Link>
            </div>
        </div>
    </div>
    <h2 class="-mx-3 md:-mx-[22px] border-b border-line bg-white px-[22px] py-[18px] text-[22px] font-semibold text-ink">Compras, Gastos y Documento Soporte</h2>

    <section class="s-card mt-[18px]">
        <div class="px-[16px] pt-[16px]">
            <span class="s-label font-normal text-muted">Tipo de transacción</span>
            <div class="flex flex-wrap items-center gap-3">
                <select v-model="f.tipo" class="s-input w-full sm:w-[300px]" @change="reload">
                    <option value="">Seleccione una opción</option>
                    <option v-for="(n, k) in TIPOS" :key="k" :value="k">{{ n }}</option>
                </select>
                <button type="button" class="s-btn s-btn-outline" @click="open = !open"><Icon icon="sliders" /> Más filtros</button>
                <a href="/movimientos" class="s-btn s-btn-outline ml-auto"><Icon icon="download" /> Descargar</a>
            </div>
            <div v-if="open" class="mt-3 flex flex-wrap items-end gap-3">
                <label class="w-full sm:w-auto"><span class="s-label">Proveedor / comprobante</span>
                    <input v-model="f.q" class="s-input w-full sm:w-[260px]" placeholder="Nombre, identificación o factura" @input="search" />
                </label>
                <label><span class="s-label">Fecha elaboración desde</span><input v-model="f.desde" type="date" class="s-input w-[160px]" @change="reload" /></label>
                <label><span class="s-label">Hasta</span><input v-model="f.hasta" type="date" class="s-input w-[160px]" @change="reload" /></label>
                <label><span class="s-label">Saldo</span>
                    <select v-model="f.saldo" class="s-input w-[170px]" @change="reload"><option value="">Todos</option><option value="pendiente">Con saldo pendiente</option></select>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2 py-3 text-[12px]">
                <span v-if="f.desde || f.hasta" class="inline-flex items-center gap-[6px] rounded-full border border-line bg-[#f5f6f7] px-2 py-[2px]">
                    Fecha elaboración: {{ last15 ? 'Últimos 15 Días' : `${f.desde || '…'} a ${f.hasta || '…'}` }}
                    <button type="button" class="text-muted" aria-label="Quitar filtro" @click="clear"><Icon icon="circle-xmark" /></button>
                </span>
                <span v-if="f.tipo" class="rounded-full border border-line bg-[#f5f6f7] px-2 py-[2px]">Tipo: {{ TIPOS[f.tipo] }}</span>
                <button type="button" class="s-btn-link" @click="clear">Limpiar filtros</button>
            </div>
        </div>
        <div class="s-table-wrap">
            <table class="s-table">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Comprobante</th>
                        <th>Factura proveedor</th>
                        <th>Fecha</th>
                        <th>Identificación</th>
                        <th>Sucursal</th>
                        <th>Proveedor</th>
                        <th>Estado</th>
                        <th>Moneda</th>
                        <th class="text-right">Valor</th>
                        <th class="text-right">Saldo</th>
                        <th>DIAN</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in documents.data" :key="d.url">
                        <td>{{ d.transaction }}</td>
                        <td><Link :href="d.url" class="text-primary">{{ d.name }}</Link><div v-if="d.annulled" class="text-[11px] text-[#c62828]">Anulado</div></td>
                        <td>{{ d.supplier_invoice || '-' }}</td>
                        <td class="whitespace-nowrap">{{ d.date }}</td>
                        <td>{{ d.identification }}</td>
                        <td>{{ d.branch }}</td>
                        <td class="max-w-[200px]"><Link :href="`/terceros/${d.contact_id}`" class="block truncate text-primary">{{ d.supplier }}</Link></td>
                        <td>-</td>
                        <td>{{ d.currency }}</td>
                        <td class="whitespace-nowrap text-right">{{ money(d.total) }}</td>
                        <td class="whitespace-nowrap text-right">{{ d.balance === null ? '-' : money(d.balance) }}</td>
                        <td>{{ { Accepted: 'Aceptado', Rejected: 'Rechazado' }[d.dian_status] ?? d.dian_status ?? '-' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!documents.data.length" />
        <Pager :paginator="documents" />
    </section>
</template>
