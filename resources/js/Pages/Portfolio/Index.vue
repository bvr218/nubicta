<script setup>
import DownloadMenu from '../../Components/DownloadMenu.vue';
import { onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';
import RowMenu from '../../Components/RowMenu.vue';

const props = defineProps({
    side: { type: String, required: true },
    cfg: { type: Object, required: true },
    rows: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const f = reactive({ ...props.filters });
const open = ref(false);
function reload() {
    router.get(props.cfg.url, Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(reload, 350);
}
const SALDO = { todos: 'Todos', con_saldo: 'Con saldo', vencido: 'Con saldo vencido' };
const ESTADO = { activo: 'Activo', inactivo: 'Inactivo', todos: 'Todos' };
const party = props.side === 'clientes' ? 'cliente' : 'proveedor';
function clear() {
    Object.assign(f, { q: '', saldo: 'todos', estado: 'activo' });
    reload();
}
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const COLUMNS = { branch: 'Sucursal', overdue: 'Saldo vencido', current: 'Saldo por vencer', credit: 'Saldo a favor', total: props.cfg.total, active: 'Estado' };
const storeKey = `portfolio-columns-${props.side}`;
const saved = typeof localStorage !== 'undefined' ? JSON.parse(localStorage.getItem(storeKey) || 'null') : null;
const visible = reactive(Object.fromEntries(Object.keys(COLUMNS).map((k) => [k, saved ? saved[k] !== false : true])));
const columnsOpen = ref(false);
const toggleColumn = (k) => {
    visible[k] = !visible[k];
    localStorage.setItem(storeKey, JSON.stringify(visible));
};
const menu = ref(null);
const createOpen = ref(false);
onMounted(() => document.addEventListener('click', () => (createOpen.value = false)));
const adjustUrl = (r) => `/contabilidad/ajustes-cartera/crear?modulo=${props.side === 'clientes' ? 'ventas' : 'compras'}&tercero=${r.id}&nombre=${encodeURIComponent(`${r.identification} - ${r.name}`)}`;
const download = (base, f) => (window.location.href = base + (base.includes('?') ? '&' : '?') + 'formato=' + f);
</script>

<template>
    <Head :title="cfg.tab" />
    <PageHeader :title="side === 'clientes' ? 'Ventas' : 'Compras y gastos'" :tabs="tabs" :active="cfg.tab" />

    <section class="s-card mt-[18px]">
        <div class="px-[16px] pt-[16px]">
            <span class="s-label font-normal text-muted">Búsqueda</span>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-full sm:w-[300px]">
                    <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                    <input v-model="f.q" class="s-input pl-9" placeholder="Buscar por nombre o identificación" @input="search" />
                </div>
                <button type="button" class="s-btn s-btn-outline relative" @click="open = !open"><Icon icon="sliders" /> Más filtros
                    <span class="absolute -right-[7px] -top-[7px] grid h-[16px] min-w-[16px] place-items-center rounded-full bg-[#e53935] px-[3px] text-[10px] text-white">2</span>
                </button>
                <div class="ml-auto flex flex-wrap justify-end gap-2">
                    <div class="relative">
                        <button type="button" class="s-btn s-btn-link" @click="columnsOpen = !columnsOpen"><Icon icon="table-columns" /> Columnas</button>
                        <div v-if="columnsOpen" class="absolute right-0 z-20 mt-1 w-[200px] rounded border border-line bg-white p-2 shadow-lg">
                            <label v-for="(n, k) in COLUMNS" :key="k" class="flex cursor-pointer items-center gap-2 px-2 py-1 text-[13px]">
                                <input type="checkbox" :checked="visible[k]" @change="toggleColumn(k)" /> {{ n }}
                            </label>
                        </div>
                    </div>
                    <DownloadMenu label="Descargar todos" title="Excel o PDF con teléfono, correo y el resto de datos" @download="(f) => download(`/terceros/excel?tipo=${party}`, f)" />
                    <Link href="/movimientos" class="s-btn s-btn-outline">Ver reportes <Icon icon="chevron-down" /></Link>
                    <div class="relative flex" @click.stop>
                        <Link :href="cfg.create" class="s-btn s-btn-primary rounded-r-none">{{ cfg.create_label }}</Link>
                        <button type="button" class="s-btn s-btn-primary rounded-l-none border-l border-[#ffffff55] px-[10px]" @click="createOpen = !createOpen"><Icon icon="caret-down" /></button>
                        <div v-if="createOpen" class="absolute right-0 top-[40px] z-30 w-[192px] rounded-[6px] bg-white py-[6px] shadow-lg">
                            <Link :href="`/terceros/importar/${side}`" class="block px-[14px] py-[8px] text-[13px] text-ink hover:bg-[#f0f9ff]">Importar {{ side }}</Link>
                            <Link href="/terceros/importar/contactos" class="block px-[14px] py-[8px] text-[13px] text-ink hover:bg-[#f0f9ff]">Importar contactos</Link>
                        </div>
                    </div>
                </div>
            </div>
            <div v-if="open" class="mt-3 flex flex-wrap items-end gap-3">
                <label><span class="s-label">{{ cfg.balance_label ?? 'Saldo de cartera' }}</span>
                    <select v-model="f.saldo" class="s-input w-[190px]" @change="reload">
                        <option v-for="(n, k) in SALDO" :key="k" :value="k">{{ n }}</option>
                    </select>
                </label>
                <label><span class="s-label">Estado de {{ party }}</span>
                    <select v-model="f.estado" class="s-input w-[150px]" @change="reload">
                        <option v-for="(n, k) in ESTADO" :key="k" :value="k">{{ n }}</option>
                    </select>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2 py-3 text-[12px]">
                <span class="inline-flex items-center gap-[6px] rounded-full border border-line bg-[#f5f6f7] px-2 py-[2px]">{{ cfg.balance_label ?? 'Saldo de cartera' }}: {{ SALDO[f.saldo] }}
                    <button type="button" class="grid h-[12px] w-[12px] place-items-center rounded-full bg-[#9aa5ae] text-[8px] text-white" title="Quitar filtro" @click="f.saldo = 'todos'; reload()"><Icon icon="xmark" /></button></span>
                <span class="inline-flex items-center gap-[6px] rounded-full border border-line bg-[#f5f6f7] px-2 py-[2px]">Estado de {{ party }}: {{ ESTADO[f.estado] }}
                    <button type="button" class="grid h-[12px] w-[12px] place-items-center rounded-full bg-[#9aa5ae] text-[8px] text-white" title="Quitar filtro" @click="f.estado = 'activo'; reload()"><Icon icon="xmark" /></button></span>
                <button type="button" class="s-btn-link disabled:text-[#c3c9ce]" :disabled="!f.q && f.saldo === 'todos' && f.estado === 'activo'" @click="clear">Limpiar filtros</button>
            </div>
        </div>

        <div class="s-table-wrap">
            <table class="sticky-actions s-table">
                <thead>
                    <tr>
                        <th>{{ cfg.party }}</th>
                        <th v-if="visible.branch">Sucursal</th>
                        <th v-if="visible.overdue" class="text-right">Saldo vencido</th>
                        <th v-if="visible.current" class="text-right">Saldo por vencer</th>
                        <th v-if="visible.credit" class="text-right">Saldo a favor</th>
                        <th v-if="visible.total" class="text-right">{{ cfg.total }}</th>
                        <th v-if="visible.active">Estado</th>
                        <th class="w-[200px]">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows.data" :key="r.id">
                        <td class="max-w-[128px]">
                            <Link :href="`${cfg.url}/${r.id}`" class="block truncate text-primary" :title="r.name">{{ r.name }}</Link>
                            <div class="text-[12px]">{{ r.identification }}</div>
                        </td>
                        <td v-if="visible.branch">{{ r.branch }}</td>
                        <td v-if="visible.overdue" class="text-right">{{ money(r.overdue) }}</td>
                        <td v-if="visible.current" class="text-right">{{ money(r.current) }}</td>
                        <td v-if="visible.credit" class="text-right">{{ money(r.credit) }}</td>
                        <td v-if="visible.total" class="text-right">{{ money(r.total) }}</td>
                        <td v-if="visible.active">{{ r.active ? 'Activo' : 'Inactivo' }}</td>
                        <td>
                            <RowMenu :open="menu === r.id" :min-width="230" anchor-class="flex" @close="menu = null">
                                <template #anchor>
                                    <Link :href="`${cfg.url}/${r.id}`" class="s-btn s-btn-outline h-[28px] flex-1 rounded-r-none text-[12px]">{{ cfg.action_label ?? 'Ver estado de cuenta' }}</Link>
                                    <button type="button" class="s-btn s-btn-outline h-[28px] rounded-l-none border-l-0 px-2" @click="menu = menu === r.id ? null : r.id"><Icon icon="caret-down" /></button>
                                </template>
                                <Link :href="adjustUrl(r)" class="block px-3 py-2 text-[13px] hover:bg-[#f5f6f7]">Ajuste de cartera y proveedores</Link>
                                <Link :href="`/terceros/${r.id}/editar`" class="block px-3 py-2 text-[13px] hover:bg-[#f5f6f7]">Editar</Link>
                            </RowMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!rows.data.length" />
        <Pager :paginator="rows" />
    </section>
</template>
