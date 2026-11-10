<script setup>
import DownloadMenu from '../../../Components/DownloadMenu.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '../../../composables/dialog';
import PageHeader from '../../../Components/PageHeader.vue';
import Pager from '../../../Components/Pager.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import RowMenu from '../../../Components/RowMenu.vue';

const props = defineProps({
    products: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    lines: { type: Array, required: true },
    groups: { type: Array, required: true },
    priceListNames: { type: Array, required: true },
});

const f = reactive({ q: '', linea: '', grupo: '', tipo: '', estado: '', saldo: '', ...props.filters });
const panel = reactive({ ...f });
const showFilters = ref(false);
const menu = ref(null);
const clean = () => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v != null));
const activeFilters = computed(() => ['linea', 'grupo', 'tipo', 'estado', 'saldo'].filter((k) => f[k]).length);
function reload() {
    router.get('/productos', clean(), { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(reload, 350);
}
function applyFilters() {
    Object.assign(f, { linea: panel.linea, grupo: panel.grupo, tipo: panel.tipo, estado: panel.estado, saldo: panel.saldo });
    showFilters.value = false;
    reload();
}
function clearFilters() {
    Object.assign(panel, { linea: '', grupo: '', tipo: '', estado: '', saldo: '' });
    applyFilters();
}
const toggle = (key) => (menu.value = menu.value === key ? null : key);
const close = () => (menu.value = null);
onMounted(() => document.addEventListener('click', close));
onBeforeUnmount(() => document.removeEventListener('click', close));

async function post(url, message) {
    close();
    if (message && !(await confirmDialog(message))) return;
    router.post(url, {}, { preserveScroll: true });
}

const reports = [
    ['Saldos de inventario', '/reportes/saldos-inventario'],
    ['Movimiento de productos / Kárdex', '/reportes/movimiento-productos'],
    ['Saldos de productos', '/reportes/saldos-productos'],
    ['Valoración de inventarios', '/reportes/valoracion-inventarios'],
];
const exportUrl = computed(() => '/productos/excel?' + new URLSearchParams(clean()).toString());
const money = (v) => Number(v).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const download = (base, f) => (window.location.href = base + (base.includes('?') ? '&' : '?') + 'formato=' + f);
</script>

<template>
    <Head title="Productos y servicios" />
    <PageHeader title="Productos y servicios" :tabs="tabs" active="Gestión de productos / servicios" />

    <section class="s-card mt-[18px]">
        <div class="flex flex-wrap items-center gap-3 px-[16px] py-[16px]">
            <div class="relative w-full sm:w-[250px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="f.q" class="s-input pl-9" placeholder="Buscar producto o servicio" @input="search" />
            </div>
            <div class="relative" @click.stop>
                <button type="button" class="s-btn s-btn-outline" @click="Object.assign(panel, f); showFilters = !showFilters">
                    <Icon icon="sliders" /> Filtros<span v-if="activeFilters" class="ml-1 rounded-full bg-primary px-[6px] text-[10px] text-white">{{ activeFilters }}</span>
                </button>
                <div v-if="showFilters" class="absolute left-0 top-[40px] z-30 w-[320px] max-w-[calc(100vw-48px)] rounded-[6px] border border-line bg-white p-[16px] shadow-lg">
                    <p class="mb-[10px] text-[13px] font-semibold">Define los filtros que deseas aplicar a la tabla:</p>
                    <label class="s-label">Línea</label>
                    <select v-model="panel.linea" class="s-input mb-[10px]">
                        <option value="">Todas</option>
                        <option v-for="l in lines" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }}</option>
                    </select>
                    <label class="s-label">Categoría</label>
                    <select v-model="panel.grupo" class="s-input mb-[10px]">
                        <option value="">Todas</option>
                        <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                    </select>
                    <label class="s-label">Tipo</label>
                    <select v-model="panel.tipo" class="s-input mb-[10px]">
                        <option value="">Todos</option>
                        <option value="Product">Producto</option>
                        <option value="Service">Servicio</option>
                    </select>
                    <label class="s-label">Estado</label>
                    <select v-model="panel.estado" class="s-input mb-[10px]">
                        <option value="">Todos</option>
                        <option value="activos">Activo</option>
                        <option value="inactivos">Inactivo</option>
                    </select>
                    <label class="s-label">Stock</label>
                    <select v-model="panel.saldo" class="s-input">
                        <option value="">Todos</option>
                        <option value="negativo">En negativo</option>
                        <option value="bajo_minimo">Bajo el mínimo</option>
                    </select>
                    <div class="mt-[14px] flex justify-between">
                        <button type="button" class="text-[13px] text-primary" @click="clearFilters">Limpiar filtros</button>
                        <div class="flex gap-2">
                            <button type="button" class="s-btn s-btn-outline" @click="showFilters = false">Cancelar</button>
                            <button type="button" class="s-btn s-btn-primary" @click="applyFilters">Filtrar</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="ml-auto flex flex-wrap items-center justify-end gap-3">
                <DownloadMenu button-class="px-[8px] text-[18px] text-primary" icon="file-excel" label="" title="Exportar" @download="(f) => download(exportUrl, f)" />
                <div class="relative" @click.stop>
                    <button type="button" class="s-btn s-btn-outline" @click="toggle('reportes')">Ver reportes</button>
                    <div v-if="menu === 'reportes'" class="absolute right-0 top-[40px] z-30 w-[260px] rounded-[6px] bg-white py-[6px] shadow-lg">
                        <Link v-for="[label, href] in reports" :key="href" :href="href" class="block px-[14px] py-[8px] text-[13px] hover:bg-[#f0f9ff]">{{ label }}</Link>
                    </div>
                </div>
                <div class="relative" @click.stop>
                    <button type="button" class="s-btn s-btn-primary" @click="toggle('crear')">Crear / Importar <Icon icon="caret-down" /></button>
                    <div v-if="menu === 'crear'" class="absolute right-0 top-[40px] z-30 w-[260px] rounded-[6px] bg-white py-[6px] shadow-lg">
                        <Link href="/productos/crear" class="block px-[14px] py-[8px] text-[13px] hover:bg-[#f0f9ff]">Crear producto</Link>
                        <Link href="/productos/importar" class="block px-[14px] py-[8px] text-[13px] hover:bg-[#f0f9ff]">Importación de productos y servicios</Link>
                    </div>
                </div>
            </div>
        </div>

        <div class="s-table-wrap">
            <table class="sticky-actions s-table">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Nombre</th>
                        <th>Código</th>
                        <th>Unidad</th>
                        <th class="text-right">Precios</th>
                        <th>Impuestos</th>
                        <th class="text-right">Stock</th>
                        <th>Estado</th>
                        <th class="w-[120px]">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id">
                        <td>{{ p.type }}</td>
                        <td>
                            <Link :href="`/productos/${p.id}`" class="text-primary">{{ p.name }}</Link>
                            <div v-if="p.line" class="text-[11px] text-muted">{{ p.line }}</div>
                        </td>
                        <td>{{ p.code }}</td>
                        <td>{{ p.unit }}</td>
                        <td class="whitespace-nowrap text-right">
                            <span v-if="p.prices.length" :title="p.prices.map((v, i) => `${priceListNames[i] ?? ''}: $${money(v)}`).join('\n')">${{ money(p.prices[0]) }} COP</span>
                            <span v-if="p.prices.length > 1" class="ml-1 text-primary" :title="p.prices.map((v, i) => `${priceListNames[i] ?? ''}: $${money(v)}`).join('\n')">•••</span>
                        </td>
                        <td>{{ p.taxes || '—' }}</td>
                        <td class="text-right" :class="{ 'text-danger': p.stock !== null && p.stock <= 0 }">{{ p.stock === null ? '—' : qty(p.stock) }}</td>
                        <td>
                            <span class="inline-flex items-center gap-1" :class="p.active ? 'text-[#2e7d32]' : 'text-muted'">
                                <Icon :icon="p.active ? 'circle-check' : 'circle-xmark'" /><span class="text-ink">{{ p.active ? 'Activo' : 'Inactivo' }}</span>
                            </span>
                        </td>
                        <td>
                            <RowMenu :open="menu === p.id" :min-width="150" @close="menu = null">
                                <template #anchor>
                                    <Link :href="`/productos/${p.id}/editar`" class="rounded-l-[4px] border border-primary px-[22px] py-[4px] text-[12px] text-primary hover:bg-[#f0f9ff]">Editar</Link>
                                    <button type="button" class="rounded-r-[4px] border border-l-0 border-primary px-[8px] text-primary hover:bg-[#f0f9ff]" @click="toggle(p.id)"><Icon icon="caret-down" /></button>
                                </template>
                                    <Link :href="`/productos/${p.id}/editar`" class="block px-[14px] py-[7px] text-[13px] hover:bg-[#f0f9ff]">Editar</Link>
                                    <button type="button" class="block w-full px-[14px] py-[7px] text-left text-[13px] hover:bg-[#f0f9ff]" @click="post(`/productos/${p.id}/estado`)">{{ p.active ? 'Inactivar' : 'Activar' }}</button>
                                    <button type="button" class="block w-full px-[14px] py-[7px] text-left text-[13px] hover:bg-[#f0f9ff]" @click="post(`/productos/${p.id}/eliminar`, `¿Eliminar el producto ${p.name}?`)">Eliminar</button>
                                    <Link :href="`/productos/crear?copiar=${p.id}`" class="block px-[14px] py-[7px] text-[13px] hover:bg-[#f0f9ff]">Duplicar</Link>
                                    <Link v-if="p.stock !== null" :href="`/productos/${p.id}/kardex`" class="block px-[14px] py-[7px] text-[13px] hover:bg-[#f0f9ff]">Kárdex</Link>
                            </RowMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!products.data.length" />
        <Pager :paginator="products" />
    </section>
</template>
