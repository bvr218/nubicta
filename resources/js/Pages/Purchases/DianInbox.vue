<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

/* Bandeja DIAN > Causacion automatica DIAN. */
const props = defineProps({
    docs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Object, required: true },
    syncs: { type: Array, default: () => [] },
    lastSync: { type: Object, default: null },
});

const f = reactive({ q: '', estado: '', desde: '', hasta: '', ...props.filters });
const list = computed(() => props.docs.data);
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const moreFilters = ref(!!(f.desde || f.hasta));
const history = ref(false);
const busy = ref(false);
const listDialog = ref(false);
const listUpload = useForm({ file: null });
let poller = null;

function reload() {
    router.get('/bandeja-dian', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
}
function sync() {
    busy.value = true;
    router.post('/bandeja-dian/sincronizar', {}, { preserveScroll: true, onFinish: () => (busy.value = false) });
}
function uploadList() {
    if (!listUpload.file) return;
    listUpload.post('/bandeja-dian/sincronizar', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            listDialog.value = false;
            listUpload.reset();
        },
    });
}
function closeListDialog() {
    if (listUpload.processing) return;
    listDialog.value = false;
    listUpload.reset();
}

onMounted(() => {
    poller = window.setInterval(() => {
        if (['En cola', 'Procesando'].includes(props.lastSync?.status)) {
            router.reload({ only: ['syncs', 'lastSync'], preserveScroll: true });
        }
    }, 5000);
});
onUnmounted(() => window.clearInterval(poller));
</script>

<template>
    <Head title="Bandeja DIAN" />
    <div class="flex items-center justify-between border-b border-line bg-white px-[22px] py-[14px]">
        <h1 class="text-[24px] font-semibold text-ink">Causación automática DIAN</h1>
        <span v-if="lastSync" class="flex items-center gap-2 rounded-full border border-line px-4 py-[6px] text-[13px] shadow-sm">
            <Icon :icon="lastSync.status === 'Exitosa' ? 'circle-check' : 'circle-exclamation'" :class="lastSync.status === 'Exitosa' ? 'text-[#2e7d32]' : ['En cola', 'Procesando'].includes(lastSync.status) ? 'text-primary' : 'text-[#c62828]'" />
            <b>{{ lastSync.status === 'Exitosa' ? 'Sincronización exitosa' : lastSync.status === 'Parcial' ? 'Importación parcial' : ['En cola', 'Procesando'].includes(lastSync.status) ? 'Importación en proceso' : 'Sincronización fallida' }}</b>
            <span class="text-line">|</span>
            <button type="button" class="text-primary" @click="history = true">Ver detalles</button>
        </span>
    </div>

    <section class="s-card mt-[18px] md:mx-[22px]">
        <form class="flex flex-wrap items-end gap-4 px-[16px] py-[16px]" @submit.prevent="reload">
            <label class="flex w-full flex-col text-[13px] text-ink sm:w-auto">Búsqueda
                <span class="relative w-full sm:w-[300px]">
                    <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                    <input v-model="f.q" class="s-input pl-9" placeholder="Buscar por proveedor o CUFE" />
                </span>
            </label>
            <label class="flex flex-col text-[13px] text-ink"><span>Estado *</span>
                <select v-model="f.estado" class="s-input w-[220px]" @change="reload">
                    <option value="">Todos</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </label>
            <button type="button" class="s-btn s-btn-outline" @click="moreFilters = !moreFilters"><Icon icon="sliders" /> Más filtros</button>
            <template v-if="moreFilters">
                <label class="flex flex-col text-[12px] text-ink-soft">Fecha desde<input v-model="f.desde" type="date" class="s-input w-[160px]" @change="reload" /></label>
                <label class="flex flex-col text-[12px] text-ink-soft">Fecha hasta<input v-model="f.hasta" type="date" class="s-input w-[160px]" @change="reload" /></label>
            </template>
            <button type="button" class="s-btn s-btn-outline ml-auto" @click="listDialog = true">Subir Listado</button>
            <button type="button" class="s-btn s-btn-primary" :disabled="busy" @click="sync">{{ busy ? 'Sincronizando…' : 'Sincronizar' }}</button>
        </form>

        <div class="s-table-wrap">
            <table class="sticky-actions s-table w-full">
                <thead><tr><th>Fecha de emisión</th><th>Factura proveedor</th><th>Proveedor u otro</th><th class="text-right">Total</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    <tr v-for="d in list" :key="d.id">
                        <td class="whitespace-nowrap">{{ d.date }}</td>
                        <td><span class="font-semibold">{{ d.number }}</span><br /><span class="text-[11px] text-muted">{{ d.kind }}</span></td>
                        <td>{{ d.supplier }}<br /><span class="text-[11px] text-muted">{{ d.nit }}</span></td>
                        <td class="text-right">{{ money(d.total) }}</td>
                        <td><span class="s-badge" :class="d.causada ? 's-badge-ok' : 's-badge-warn'">{{ d.status }}</span></td>
                        <td class="whitespace-nowrap">
                            <Link v-if="d.purchase" :href="d.purchase.url" class="mr-3 font-semibold text-primary hover:underline">{{ d.purchase.name }}</Link>
                            <Link v-else-if="d.canCause && d.causeUrl" :href="d.causeUrl" class="mr-3 font-semibold text-primary hover:underline">Causar</Link>
                            <span v-else-if="d.waitingForBase" class="mr-3 text-[12px] text-muted">Esperando documento base</span>
                            <span v-else class="mr-3 text-[12px] text-muted">No aplicable</span>
                            <a :href="`/bandeja-dian/${d.id}/xml`" class="font-semibold text-primary hover:underline">XML</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!list.length" title="No se encontraron resultados para tu búsqueda." text="Modifica tus criterios de búsqueda o filtrado e inténtalo de nuevo." />
        <Pager v-if="list.length" :paginator="docs" />
    </section>

    <div v-if="history" class="fixed inset-0 z-50 bg-black/40" @click.self="history = false">
        <div class="absolute inset-x-0 top-0 flex max-h-[calc(100vh-24px)] flex-col overflow-hidden bg-[#f5f7f9] pb-6">
            <div class="flex shrink-0 items-center justify-between bg-primary px-5 py-4 text-white">
                <h3 class="text-[15px] font-semibold">Historial de sincronización</h3>
                <button type="button" @click="history = false"><Icon icon="xmark" /></button>
            </div>
            <div class="s-card mx-5 mt-5 flex min-h-0 flex-1 flex-col overflow-hidden">
                <p class="shrink-0 px-4 py-4 text-[14px]">Última sincronización: {{ lastSync?.date ?? '-' }}</p>
                <div class="min-h-0 flex-1 overflow-auto">
                    <div class="s-table-wrap">
                    <table class="s-table w-full">
                        <thead><tr><th>Fecha de Solicitud</th><th>Estado</th><th>Progreso</th><th>Documentos recibidos</th><th>Observaciones</th></tr></thead>
                        <tbody>
                            <tr v-for="(s, i) in syncs" :key="i">
                                <td class="whitespace-nowrap">{{ s.date }}</td><td>{{ s.status }}</td><td>{{ s.processed }} / {{ s.total }}</td><td>{{ s.received }}</td><td class="text-[12px]">{{ s.observations }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <EmptyState v-if="!syncs.length" title="No se encontraron resultados para tu búsqueda." text="Modifica tus criterios de búsqueda o filtrado e inténtalo de nuevo." />
                </div>
            </div>
        </div>
    </div>

    <div v-if="listDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @click.self="closeListDialog">
        <section class="s-card w-full max-w-[560px] p-5">
            <h2 class="text-[18px] font-semibold text-ink">Subir Listado</h2>
            <p class="mt-3 text-[14px] text-ink">Selecciona el archivo excel con la lista de CUFE/CUDE para obtenerlos y causarlos, descarga este listado de la DIAN ingresando por Facturación Electronica/Histórico/Descarga de listados</p>
            <label class="mt-4 flex cursor-pointer flex-col gap-2 text-[13px] text-ink">
                Archivo Excel
                <input type="file" accept=".xls,.xlsx" class="s-input" @change="listUpload.file = $event.target.files?.[0] ?? null" />
                <span class="text-[11px] text-muted">Formatos .xls o .xlsx, máximo 10 MB.</span>
            </label>
            <p v-if="listUpload.errors.file" class="s-error mt-2">{{ listUpload.errors.file }}</p>
            <div class="mt-5 flex justify-end gap-3">
                <button type="button" class="s-btn s-btn-outline" :disabled="listUpload.processing" @click="closeListDialog">Cancelar</button>
                <button type="button" class="s-btn s-btn-primary" :disabled="listUpload.processing || !listUpload.file" @click="uploadList">
                    {{ listUpload.processing ? 'Cargando…' : 'Cargar' }}
                </button>
            </div>
        </section>
    </div>
</template>
