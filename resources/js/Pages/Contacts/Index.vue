<script setup>
import DownloadMenu from '../../Components/DownloadMenu.vue';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    contacts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    title: { type: String, required: true },
    module: { type: Object, default: null },
    counts: { type: Object, required: true },
});

const q = ref(props.filters.q ?? '');
const estado = ref(props.filters.estado ?? '');
const tipo = computed(() => props.filters.tipo ?? '');

function reload(extra = {}) {
    router.get('/terceros', { q: q.value || undefined, tipo: tipo.value || undefined, estado: estado.value || undefined, ...extra }, { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(reload, 350);
}

const createLabel = computed(() => ({ cliente: 'Crear cliente', proveedor: 'Crear proveedor', empleado: 'Crear empleado' })[tipo.value] ?? 'Crear tercero');
const exportUrl = computed(() => '/terceros/excel?' + new URLSearchParams(Object.entries({ tipo: tipo.value }).filter(([, v]) => v)).toString());
const roleTabs = [
    { key: '', label: 'Todos' },
    { key: 'cliente', label: 'Clientes' },
    { key: 'proveedor', label: 'Proveedores' },
    { key: 'empleado', label: 'Empleados' },
    { key: 'otro', label: 'Otros' },
];
const download = (base, f) => (window.location.href = base + (base.includes('?') ? '&' : '?') + 'formato=' + f);
</script>

<template>
    <Head :title="title" />
    <PageHeader v-if="module" :title="module.title" :tabs="module.tabs" :active="module.active" />
    <PageHeader v-else :title="title" />

    <section class="s-card mt-[18px]">
        <div class="flex flex-wrap items-center gap-3 px-[16px] py-[16px]">
            <div class="relative w-full sm:w-[320px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="q" class="s-input pl-9" placeholder="Buscar por identificación o nombre" @input="search" />
            </div>
            <select v-model="estado" class="s-input w-[140px]" @change="reload()">
                <option value="">Todos</option>
                <option value="activos">Activos</option>
                <option value="inactivos">Inactivos</option>
            </select>
            <div v-if="!module" class="flex gap-1">
                <button v-for="r in roleTabs" :key="r.key" type="button" class="s-btn"
                    :class="tipo === r.key ? 's-btn-primary' : 's-btn-outline'" @click="router.get('/terceros', { tipo: r.key || undefined })">
                    {{ r.label }}<span v-if="r.key" class="opacity-75">({{ counts[r.key] }})</span>
                </button>
            </div>
            <div class="ml-auto flex flex-wrap justify-end gap-2">
                <DownloadMenu label="Descargar todos" title="Excel o PDF con todos los datos" @download="(f) => download(exportUrl, f)" />
                <Link :href="`/terceros/crear${tipo ? '?tipo=' + tipo : ''}`" class="s-btn s-btn-primary w-[150px]">{{ createLabel }}</Link>
            </div>
        </div>

        <div class="s-table-wrap">
            <table class="sticky-actions s-table">
                <thead>
                    <tr>
                        <th>Identificación</th>
                        <th>Nombre</th>
                        <th>Tipo de tercero</th>
                        <th>Ciudad</th>
                        <th>Teléfono</th>
                        <th>Correo electrónico</th>
                        <th>Estado</th>
                        <th class="w-[80px]">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in contacts.data" :key="c.id">
                        <td>
                            <div class="text-ink">{{ c.identification }}<span v-if="c.branch_office" class="text-muted"> · Suc. {{ c.branch_office }}</span></div>
                            <div class="text-[11px] text-muted">{{ c.identification_type }}</div>
                        </td>
                        <td><Link :href="`/terceros/${c.id}`" class="text-primary">{{ c.name }}</Link></td>
                        <td>{{ c.roles }}</td>
                        <td>{{ c.city ?? '' }}</td>
                        <td>{{ c.phone ?? '' }}</td>
                        <td class="max-w-[240px] truncate">{{ c.email ?? '' }}</td>
                        <td><span class="s-badge" :class="c.active ? 's-badge-ok' : 's-badge-off'">{{ c.active ? 'Activo' : 'Inactivo' }}</span></td>
                        <td>
                            <Link :href="`/terceros/${c.id}/editar`" class="text-primary" title="Editar"><Icon icon="pen" /></Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!contacts.data.length" />
        <Pager :paginator="contacts" />
    </section>
</template>
