<script setup>
import DownloadMenu from '../Components/DownloadMenu.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { alertDialog, confirmDialog } from '../composables/dialog';
import PageHeader from '../Components/PageHeader.vue';
import Pager from '../Components/Pager.vue';
import EmptyState from '../Components/EmptyState.vue';
import RowMenu from '../Components/RowMenu.vue';

/*
 * Listado generico. Celdas: texto o {text, href, badge, align, truncate, button, post, confirm, download, select, split: {text, download|href}, menu: [{text, href|post}]}.
 * Botones: {label, href | post | download | upload | menu: [{label, href}] | selection: 'open'|'post', url, confirm}.
 */
const props = defineProps({
    view: { type: Object, required: true },
    rows: { type: [Array, Object], default: () => [] },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});

const thisYear = new Date().getFullYear();
const f = reactive({ q: '', desde: '', hasta: '', sub: props.view.subtabs?.[0] ?? '', anio: String(thisYear), usuario: '', rango: 'Rango', ...Object.fromEntries((props.view.selects ?? []).map((s) => [s.name, s.multiple ? [] : ''])), ...props.filters });
for (const s of props.view.selects ?? []) {
    if (s.multiple && !Array.isArray(f[s.name])) f[s.name] = f[s.name] ? [f[s.name]] : [];
}
const paginator = computed(() => (Array.isArray(props.rows) ? null : props.rows));
const list = computed(() => (Array.isArray(props.rows) ? props.rows : props.rows.data));
const columns = computed(() => props.view.subtab_columns?.[f.sub] ?? props.view.columns);
const cell = (c) => (c !== null && typeof c === 'object' ? c : { text: c ?? '' });
const badgeClass = { ok: 's-badge-ok', warn: 's-badge-warn', danger: 's-badge-danger', off: '' };
const stickyActions = computed(() => {
    const cols = columns.value ?? [];
    if (/acci[oó]n/i.test(String(cols[cols.length - 1] ?? ''))) return true;
    return list.value.some((r) => {
        const last = cell(r[r.length - 1]);
        return !!(last.actions || last.split || last.menu || last.button);
    });
});

const selected = ref([]);
const selectable = computed(() => list.value.map((r) => r.find((c) => c?.select)?.select).filter(Boolean));
const allSelected = computed(() => selectable.value.length > 0 && selectable.value.every((id) => selected.value.includes(id)));
const toggleAll = () => (selected.value = allSelected.value ? [] : [...selectable.value]);
const busy = ref(false);
const menuOpen = ref(null);
const closeMenu = () => (menuOpen.value = null);
const openMultiFilter = ref(null);
const multiFilterRefs = new Map();
const setMultiFilterRef = (name, el) => el ? multiFilterRefs.set(name, el) : multiFilterRefs.delete(name);
const closeMultiFilter = () => (openMultiFilter.value = null);
function toggleMultiFilter(name) {
    openMultiFilter.value = openMultiFilter.value === name ? null : name;
}
function closeMultiFilterOnOutside(event) {
    if (openMultiFilter.value && !multiFilterRefs.get(openMultiFilter.value)?.contains(event.target)) closeMultiFilter();
}
function closeMultiFilterOnEscape(event) {
    if (event.key === 'Escape') closeMultiFilter();
}
onMounted(() => {
    document.addEventListener('click', closeMenu);
    document.addEventListener('pointerdown', closeMultiFilterOnOutside);
    document.addEventListener('keydown', closeMultiFilterOnEscape);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeMenu);
    document.removeEventListener('pointerdown', closeMultiFilterOnOutside);
    document.removeEventListener('keydown', closeMultiFilterOnEscape);
});

function reload(extra = {}) {
    Object.assign(f, extra);
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => Array.isArray(v) ? v.length > 0 : Boolean(v)));
    if (params.sub === props.view.subtabs?.[0]) delete params.sub;
    if (params.anio === String(thisYear)) delete params.anio;
    selected.value = [];
    router.get(props.view.url, params, { preserveState: true, replace: true });
}

async function post(url, data = {}, confirmText = null) {
    if (confirmText && !(await confirmDialog(confirmText))) return;
    busy.value = true;
    router.post(url, data, { preserveScroll: true, onFinish: () => (busy.value = false), onSuccess: () => (selected.value = []) });
}

function runButton(b) {
    if (b.selection) {
        if (!selected.value.length) return alertDialog('Selecciona al menos un comprobante de la lista.');
        if (b.selection === 'open') return window.open(`${b.url}?ids=${selected.value.join(',')}`, '_blank');
        return post(b.url, { ids: selected.value }, b.confirm?.replace(':n', selected.value.length));
    }
    if (b.post) post(b.post, {}, b.confirm);
}
function upload(b, file) {
    if (!file) return;
    busy.value = true;
    router.post(b.upload, { file }, { forceFormData: true, preserveScroll: true, onError: (e) => alertDialog(Object.values(e)[0]), onFinish: () => (busy.value = false) });
}
const criteria = ref(true);
const ranges = ['Últimos 15 días', 'Este mes', 'Mes anterior', 'Este trimestre', 'Rango'];
const years = [2015, 2016, 2017, ...Array.from({ length: thisYear - 2019 }, (_, i) => 2020 + i)];
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
function applyRange() {
    const today = new Date();
    const base = Number(f.anio) === thisYear ? today : new Date(Number(f.anio), 11, 31);
    const y = base.getFullYear();
    const m = base.getMonth();
    const span = {
        'Últimos 15 días': [new Date(y, m, base.getDate() - 14), base],
        'Este mes': [new Date(y, m, 1), new Date(y, m + 1, 0)],
        'Mes anterior': [new Date(y, m - 1, 1), new Date(y, m, 0)],
        'Este trimestre': [new Date(y, m - (m % 3), 1), new Date(y, m - (m % 3) + 3, 0)],
    }[f.rango];
    if (span) [f.desde, f.hasta] = span.map(iso);
}
const year = computed(() => f.anio);
const fill = (s) => String(s ?? '').replace(':anio', year.value);
function goDownload(b, formato) {
    const url = downloadHref(b);
    window.location.href = `${url}${url.includes('?') ? '&' : '?'}formato=${formato}`;
}
function downloadHref(b) {
    const qs = new URLSearchParams();
    for (const [key, value] of Object.entries(f)) {
        if (Array.isArray(value)) value.forEach((v) => qs.append(`${key}[]`, v));
        else if (value) qs.set(key, value);
    }
    const query = qs.toString();
    const url = fill(b.download);
    return query ? `${url}${url.includes('?') ? '&' : '?'}${query}` : url;
}
</script>

<template>
    <Head :title="view.title" />
    <PageHeader :title="view.title" :tabs="tabs" :active="view.tab" />

    <div v-if="view.heading" :class="view.blue ? 'mt-[14px]' : 'mt-[26px] px-[22px]'">
        <h2 class="text-[22px] font-semibold" :class="view.blue ? 'text-ink' : 'text-primary'">{{ view.heading }}</h2>
        <p v-if="view.intro" class="mt-[10px] text-[13px] text-ink">{{ view.intro }}</p>
    </div>

    <div v-if="view.info" class="md:mx-[22px] mt-[16px] flex gap-3 rounded border border-[#bae6fd] bg-[#f0f9ff] px-4 py-3 text-[13px] text-ink">
        <Icon icon="circle-info" class="mt-[2px] text-primary" />
        <p>{{ view.info }}</p>
    </div>

    <section v-if="view.blue" class="mt-[18px]">
        <form class="flex flex-wrap items-center gap-y-2" @submit.prevent="reload()">
            <input v-model="f.q" class="h-[28px] w-[calc(100%-23px)] rounded-l-[4px] sm:w-[311px] bg-[#eceff1] px-[8px] text-[12px] outline-none" :placeholder="view.search" />
            <button type="submit" class="grid h-[28px] w-[23px] place-items-center rounded-r-[4px] bg-[#c9ced3] text-[11px] text-muted" aria-label="Buscar"><Icon icon="magnifying-glass" /></button>
            <Link v-for="b in (view.buttons ?? []).filter((x) => x.href)" :key="b.label" :href="fill(b.href)" class="s-btn s-btn-primary ml-auto">{{ b.label }}</Link>
        </form>
        <div class="mt-[12px] rounded-[4px] bg-white shadow-sm">
            <div class="s-table-wrap rounded-t-[4px]">
            <table class="s-table w-full" :class="stickyActions && 'sticky-actions'">
                <thead><tr><th v-for="c in columns" :key="c" class="!bg-[#0275b3] !text-white">{{ c }}</th></tr></thead>
                <tbody>
                    <tr v-for="(r, i) in list" :key="i">
                        <td v-for="(raw, j) in r" :key="j">
                            <template v-if="cell(raw).actions">
                                <template v-for="a in cell(raw).actions" :key="a.text">
                                    <Link v-if="a.href" :href="a.href" class="mr-3 font-semibold text-primary hover:underline">{{ a.text }}</Link>
                                    <button v-else-if="a.post" type="button" class="mr-3 font-semibold hover:underline" :class="a.danger ? 'text-[#c62828]' : 'text-primary'" :disabled="busy" @click="post(a.post, a.data ?? {}, a.confirm)">{{ a.text }}</button>
                                </template>
                            </template>
                            <Link v-else-if="cell(raw).href" :href="cell(raw).href" class="font-semibold text-primary hover:underline">{{ cell(raw).text }}</Link>
                            <template v-else>{{ cell(raw).text }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
            <template v-if="!list.length">
                <EmptyState icon="file-circle-question" v-bind="view.empty ? { title: view.empty[0], text: view.empty[1] } : {}" />
                <div v-if="view.empty_button" class="-mt-[20px] pb-[18px] text-center">
                    <Link :href="view.empty_button.href" class="s-btn s-btn-primary !px-[14px] !text-[14px]">{{ view.empty_button.label }}</Link>
                </div>
            </template>
            <Pager v-if="paginator" :paginator="paginator" />
        </div>
    </section>

    <section v-else-if="view.classic" class="mt-[20px] rounded-[4px] bg-white px-[10px] sm:px-[14px] pb-[24px] pt-[22px] text-[12px] text-ink">
        <button type="button" class="flex items-center gap-1 text-[12px] text-ink" @click="criteria = !criteria">
            <Icon :icon="criteria ? 'chevron-up' : 'chevron-down'" class="text-[9px] text-primary" /> {{ criteria ? 'Ocultar' : 'Mostrar' }} criterios de búsqueda
        </button>
        <form v-show="criteria" class="mt-[8px] rounded-[4px] border border-line px-[10px] pb-[8px] pt-[18px] sm:px-[20px]" @submit.prevent="reload({ page: '' })">
            <div class="grid grid-cols-1 gap-x-[40px] gap-y-[10px] lg:grid-cols-2 lg:gap-y-0">
                <label class="flex items-start gap-[12px]"><span class="w-[86px] shrink-0 pt-[3px] text-right">{{ view.search_label }}</span>
                    <span class="relative w-full max-w-[338px]"><input v-model="f.q" class="h-[20px] w-full border border-[#c9ced3] px-[3px] pr-[20px]" placeholder="Buscar" />
                        <Icon icon="magnifying-glass" class="absolute right-[5px] top-[4px] text-[11px]" /></span>
                </label>
                <div class="flex items-start gap-[12px]"><span class="w-[86px] shrink-0 text-right leading-[18px]">Fecha<br />elaboración</span>
                    <div class="flex flex-wrap gap-[4px]">
                        <select v-model="f.anio" class="h-[20px] w-[84px] border border-[#c9ced3]" @change="applyRange()">
                            <option v-for="y in years" :key="y" :value="String(y)">{{ y }}</option>
                        </select>
                        <select v-model="f.rango" class="h-[20px] w-[112px] border border-[#c9ced3]" @change="applyRange()">
                            <option v-for="r in ranges" :key="r" :value="r">{{ r }}</option>
                        </select>
                        <div class="flex flex-col gap-[6px]">
                            <input v-model="f.desde" type="date" class="h-[20px] w-[120px] border border-[#c9ced3] px-[3px]" @change="f.rango = 'Rango'" />
                            <input v-model="f.hasta" type="date" class="h-[20px] w-[120px] border border-[#c9ced3] px-[3px]" @change="f.rango = 'Rango'" />
                        </div>
                    </div>
                </div>
                <span class="hidden lg:block"></span>
                <label class="flex items-center gap-[12px] lg:mt-[14px]"><span class="w-[86px] shrink-0 text-right">Usuario</span>
                    <span class="relative w-full max-w-[338px]"><input v-model="f.usuario" class="h-[20px] w-full border border-[#c9ced3] px-[3px] pr-[20px]" />
                        <button v-if="f.usuario" type="button" class="absolute right-[6px] top-[2px] font-bold" @click="f.usuario = ''">×</button></span>
                </label>
            </div>
            <div class="mt-[16px] flex items-center gap-[20px] pl-[22px]">
                <button type="submit" class="rounded-[4px] border border-line px-[11px] py-[6px] font-bold text-primary shadow-sm">Buscar</button>
                <Link :href="`${view.url}?todos=1`" class="font-bold text-primary">Limpiar filtros</Link>
            </div>
        </form>
        <div class="mt-[8px] flex justify-end">
            <a :href="downloadHref({ download: `${view.url}/excel` })" class="grid h-[26px] w-[26px] place-items-center rounded-[3px] border border-line" title="Exportar a Excel"><Icon icon="file-excel" /></a>
        </div>
        <div class="s-table-wrap mt-[8px]">
        <table class="w-full">
            <thead><tr class="bg-[#0275b3] text-left text-[12px] font-bold text-white">
                <th v-for="(c, j) in columns" :key="c" class="px-[6px] py-[6px]" :class="j === columns.length - 1 && 'text-right'">{{ c }}</th>
            </tr></thead>
            <tbody>
                <tr v-for="(r, i) in list" :key="i" class="border-b border-line">
                    <td v-for="(raw, j) in r" :key="j" class="px-[6px] py-[6px]" :class="cell(raw).align === 'right' && 'text-right'">
                        <span v-if="cell(raw).badge" class="s-badge" :class="badgeClass[cell(raw).badge]">{{ cell(raw).text }}</span>
                        <Link v-else-if="cell(raw).href" :href="cell(raw).href" class="text-primary hover:underline">{{ cell(raw).text }}</Link>
                        <template v-else>{{ cell(raw).text }}</template>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
        <p v-if="!list.length" class="py-[66px] text-center text-[14px] text-muted">No se encontraron resultados para tu búsqueda</p>
        <Pager v-if="paginator && paginator.total > paginator.per_page" :paginator="paginator" />
    </section>

    <section v-else class="s-card mt-[18px]">
        <div v-if="view.subtabs" class="flex gap-6 overflow-x-auto border-b border-line px-[16px]">
            <button v-for="s in view.subtabs" :key="s" type="button" class="-mb-px whitespace-nowrap border-b-[3px] py-3 text-[14px] font-semibold"
                :class="f.sub === s ? 'border-primary text-primary' : 'border-transparent text-ink-soft'" @click="reload({ sub: s })">{{ s }}</button>
        </div>

        <form class="flex flex-wrap items-end gap-3 px-[16px] py-[16px]" @submit.prevent="reload()">
            <label v-if="view.year" class="flex w-full flex-col text-[12px] text-ink-soft sm:w-auto">Año
                <select v-model="f.anio" class="s-input sm:w-[140px]" @change="reload()">
                    <option v-for="y in 6" :key="y" :value="String(thisYear - y + 1)">{{ thisYear - y + 1 }}</option>
                </select>
            </label>
            <label v-if="view.search" class="flex w-full flex-col text-[12px] text-ink-soft sm:w-auto">{{ view.search_label ?? '' }}
                <span class="relative w-full sm:w-[300px]">
                    <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                    <input v-model="f.q" class="s-input pl-9" :placeholder="view.search" />
                </span>
            </label>
            <template v-if="view.dates">
                <label class="flex w-[calc(50%-6px)] flex-col text-[12px] text-ink-soft sm:w-auto">Fecha desde<input v-model="f.desde" type="date" class="s-input sm:w-[160px]" /></label>
                <label class="flex w-[calc(50%-6px)] flex-col text-[12px] text-ink-soft sm:w-auto">Fecha hasta<input v-model="f.hasta" type="date" class="s-input sm:w-[160px]" /></label>
            </template>
            <div v-for="s in view.selects ?? []" :key="s.name" class="flex w-full flex-col text-[12px] text-ink-soft sm:w-auto">
                <span>{{ s.label }}</span>
                <div v-if="s.multiple" :ref="(el) => setMultiFilterRef(s.name, el)" class="relative">
                    <button type="button" class="s-input flex w-full cursor-pointer items-center justify-between gap-3 text-left sm:w-[190px]"
                        :aria-expanded="openMultiFilter === s.name" @click.stop="toggleMultiFilter(s.name)">
                        {{ f[s.name].length ? `${f[s.name].length} seleccionados` : 'Todos' }} <Icon icon="chevron-down" class="text-[10px]" />
                    </button>
                    <div v-if="openMultiFilter === s.name" class="absolute left-0 top-full z-30 mt-1 flex max-h-[60vh] w-[min(90vw,280px)] flex-col overflow-hidden rounded border border-line bg-white shadow-lg sm:w-[280px]">
                        <div class="overflow-y-auto py-1">
                            <label v-for="o in s.options" :key="o" class="flex min-h-[44px] cursor-pointer items-center gap-3 whitespace-nowrap px-4 py-2 text-[14px] text-ink hover:bg-[#f0f9ff]">
                                <input v-model="f[s.name]" type="checkbox" class="h-5 w-5 shrink-0 accent-primary" :value="o" @change="reload()" />
                                {{ o }}
                            </label>
                        </div>
                        <div class="flex shrink-0 justify-between gap-3 border-t border-line bg-white p-2">
                            <button type="button" class="min-h-[40px] px-3 text-[13px] font-semibold text-primary hover:bg-[#f0f9ff]"
                                :disabled="!f[s.name].length" @click="f[s.name] = []; reload()">
                                Limpiar
                            </button>
                            <button type="button" class="s-btn s-btn-primary min-h-[40px] px-4" @click="closeMultiFilter">Listo</button>
                        </div>
                    </div>
                </div>
                <select v-else v-model="f[s.name]" class="s-input sm:w-[170px]" @change="reload()">
                    <option value="">Todos</option>
                    <option v-for="o in s.options" :key="o" :value="o">{{ o }}</option>
                </select>
            </div>
            <span v-for="c in view.chips ?? []" :key="c.text ?? c" class="inline-flex items-center gap-2 rounded-full bg-[#e0f2fe] px-3 py-[6px] text-[12px] text-primary">{{ c.text ?? c }}
                <Link v-if="c.clear" :href="c.clear" title="Quitar filtro"><Icon icon="circle-xmark" /></Link>
            </span>
            <Link v-if="(view.chips ?? []).some((c) => c.clear)" :href="view.clear_url ?? view.chips.find((c) => c.clear).clear" class="pb-[6px] text-[12px] text-primary">Limpiar filtros</Link>
            <button v-if="(view.search && !view.search_only) || view.dates" type="submit" class="s-btn s-btn-outline"><Icon icon="sliders" /> Más filtros</button>
            <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                <span v-if="selected.length" class="text-[12px] text-muted">{{ selected.length }} seleccionado(s)</span>
                <template v-for="b in view.buttons ?? []" :key="b.label">
                    <div v-if="b.menu" class="relative" @click.stop>
                        <button type="button" class="s-btn" :class="b.style === 'outline' ? 's-btn-outline' : 's-btn-primary'" @click="menuOpen = menuOpen === b.label ? null : b.label">
                            <Icon v-if="b.icon" :icon="b.icon" /> {{ b.label }} <Icon icon="caret-down" />
                        </button>
                        <div v-if="menuOpen === b.label" class="absolute right-0 top-[40px] z-30 min-w-[240px] rounded-[6px] bg-white py-[6px] shadow-lg">
                            <Link v-for="m in b.menu" :key="m.label" :href="fill(m.href)" class="block whitespace-nowrap px-[14px] py-[8px] text-[13px] text-ink hover:bg-[#f0f9ff]">{{ m.label }}</Link>
                        </div>
                    </div>
                    <a v-else-if="b.external" :href="list.length ? downloadHref({ download: b.href }) : null" class="s-btn s-btn-outline"
                        :class="!list.length && 'pointer-events-none opacity-50'"><Icon v-if="b.icon" :icon="b.icon" /> {{ b.label }}</a>
                    <Link v-else-if="b.href" :href="fill(b.href)" class="s-btn" :class="b.style === 'outline' ? 's-btn-outline' : 's-btn-primary'">
                        <Icon v-if="b.icon" :icon="b.icon" /> {{ b.label }}
                    </Link>
                    <label v-else-if="b.upload" class="s-btn cursor-pointer" :class="b.style === 'outline' ? 's-btn-outline' : 's-btn-primary'">
                        <Icon v-if="b.icon" :icon="b.icon" /> {{ b.label }}
                        <input type="file" class="hidden" :accept="b.accept" :disabled="busy" @change="upload(b, $event.target.files[0]); $event.target.value = ''" />
                    </label>
                    <DownloadMenu v-else-if="b.download" :label="b.label" :icon="b.icon || 'download'" :button-class="`s-btn ${b.style === 'outline' ? 's-btn-outline' : 's-btn-primary'}`"
                        @download="(x) => goDownload(b, x)" />
                    <button v-else type="button" class="s-btn disabled:opacity-60" :class="b.style === 'outline' ? 's-btn-outline' : 's-btn-primary'" :disabled="busy" @click="runButton(b)">
                        <Icon v-if="b.icon" :icon="b.icon" /> {{ b.label }}
                    </button>
                </template>
            </div>
        </form>

        <div class="s-table-wrap">
            <table class="s-table w-full" :class="stickyActions && 'sticky-actions'">
                <thead>
                    <tr>
                        <th v-for="c in columns" :key="c">
                            <input v-if="c === '#select'" type="checkbox" :checked="allSelected" title="Seleccionar todos" @change="toggleAll" />
                            <template v-else>{{ c }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in list" :key="i">
                        <td v-for="(raw, j) in r" :key="j" :class="['whitespace-nowrap', cell(raw).align === 'right' && 'text-right', cell(raw).truncate && 'max-w-[220px] truncate']" :title="cell(raw).truncate ? cell(raw).text : null">
                            <input v-if="cell(raw).select" v-model="selected" type="checkbox" :value="cell(raw).select" />
                            <span v-else-if="cell(raw).icon_only" class="inline-flex items-center gap-1">
                                <Link v-if="cell(raw).href" :href="cell(raw).href" class="inline-flex items-center justify-center" :title="cell(raw).title" :aria-label="cell(raw).title">
                                    <Icon :icon="cell(raw).icon" :class="cell(raw).icon_class" />
                                </Link>
                                <span v-else :title="cell(raw).title" :aria-label="cell(raw).title">
                                    <Icon :icon="cell(raw).icon" :class="cell(raw).icon_class" />
                                </span>
                                <Link v-if="cell(raw).link_text && cell(raw).href" :href="cell(raw).href" class="font-semibold text-primary hover:underline" :title="cell(raw).link_title">
                                    {{ cell(raw).link_text }}
                                </Link>
                            </span>
                            <span v-else-if="cell(raw).badge" class="s-badge inline-flex items-center gap-1" :class="badgeClass[cell(raw).badge]">
                                <Icon v-if="cell(raw).icon" :icon="cell(raw).icon" /> {{ cell(raw).text }}
                            </span>
                            <RowMenu v-else-if="cell(raw).split" :open="menuOpen === `row${i}`" @close="menuOpen = null">
                                <template #anchor>
                                <a v-if="cell(raw).split.download" :href="cell(raw).split.download" class="rounded-l-[4px] border border-primary px-[34px] py-[4px] text-[12px] text-primary hover:bg-[#f0f9ff]">{{ cell(raw).split.text }}</a>
                                <Link v-else :href="cell(raw).split.href" class="rounded-l-[4px] border border-primary px-[34px] py-[4px] text-[12px] text-primary hover:bg-[#f0f9ff]">{{ cell(raw).split.text }}</Link>
                                <button type="button" class="rounded-r-[4px] border border-l-0 border-primary px-[8px] text-primary hover:bg-[#f0f9ff] disabled:opacity-40" :disabled="!cell(raw).menu?.length" @click="menuOpen = menuOpen === `row${i}` ? null : `row${i}`"><Icon icon="caret-down" /></button>
                                </template>
                                <template v-for="a in cell(raw).menu" :key="a.text">
                                    <Link v-if="a.href" :href="a.href" class="block whitespace-nowrap px-[14px] py-[7px] text-[13px] hover:bg-[#f0f9ff]">{{ a.text }}</Link>
                                    <button v-else type="button" class="block w-full whitespace-nowrap px-[14px] py-[7px] text-left text-[13px] hover:bg-[#f0f9ff]" :disabled="busy" @click="menuOpen = null; post(a.post, a.data ?? {}, a.confirm)">{{ a.text }}</button>
                                </template>
                            </RowMenu>
                            <template v-else-if="cell(raw).actions">
                                <template v-for="a in cell(raw).actions" :key="a.text">
                                    <Link v-if="a.href" :href="a.href" class="mr-3 font-semibold text-primary hover:underline">{{ a.text }}</Link>
                                    <a v-else-if="a.download" :href="a.download" class="mr-3 font-semibold text-primary hover:underline">{{ a.text }}</a>
                                    <button v-else-if="a.post" type="button" class="mr-3 font-semibold hover:underline" :class="a.danger ? 'text-[#c62828]' : 'text-primary'" :disabled="busy" @click="post(a.post, a.data ?? {}, a.confirm)">{{ a.text }}</button>
                                </template>
                            </template>
                            <Link v-else-if="cell(raw).href" :href="cell(raw).href" class="font-semibold text-primary hover:underline">{{ cell(raw).text }}</Link>
                            <a v-else-if="cell(raw).download" :href="cell(raw).download" class="font-semibold text-primary hover:underline">{{ cell(raw).text }}</a>
                            <button v-else-if="cell(raw).post" type="button" class="font-semibold text-primary hover:underline" :disabled="busy" @click="post(cell(raw).post, cell(raw).data ?? {}, cell(raw).confirm)">{{ cell(raw).text }}</button>
                            <template v-else>{{ cell(raw).text }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!list.length" v-bind="view.empty ? { title: view.empty[0], text: view.empty[1] } : {}" />
        <div v-if="!list.length && view.empty_button" class="-mt-[10px] pb-[24px] text-center">
            <Link :href="view.empty_button.href" class="s-btn s-btn-primary">{{ view.empty_button.label }}</Link>
        </div>
        <Pager v-if="paginator" :paginator="paginator" />
    </section>
</template>
