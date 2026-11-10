<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import DownloadMenu from '../../Components/DownloadMenu.vue';

/* Reporte del catalogo con la pantalla de Siigo: filtro principal, "Más filtros" en panel lateral, chips, Descargar y total general. */
const props = defineProps({ slug: String, report: Object, rows: Object, totals: Object, filters: Object, options: Object });

const has = (k) => props.report.filters.includes(k);
const label = (k) => props.report.labels[k] ?? k;
const primary = computed(() => props.report.primary.filter(has));
const extra = computed(() => props.report.filters.filter((k) => !primary.value.includes(k) && k !== 'currency'));

const today = new Date();
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
function periodRange(period, year) {
    const m = today.getMonth();
    const start = { mes: m, trimestre: m - (m % 3), semestre: m < 6 ? 0 : 6, anio: 0 }[period];
    const len = { mes: 1, trimestre: 3, semestre: 6, anio: 12 }[period];
    if (start === undefined) return null;
    return [iso(new Date(year, start, 1)), iso(new Date(year, start + len, 0))];
}
function detectPeriod(from, to) {
    const y = Number(String(from).slice(0, 4));
    for (const p of ['mes', 'trimestre', 'semestre', 'anio']) {
        const r = periodRange(p, y);
        if (r && r[0] === from && r[1] === to) return p;
    }
    return 'rango';
}
const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const PERIODS = { mes: 'Este mes', trimestre: 'Este trimestre', semestre: 'Este semestre', anio: 'Este año', rango: 'Rango' };

const initial = () => ({
    desde: props.filters.from, hasta: props.filters.to, periodo: props.filters.preset ?? detectPeriod(props.filters.from, props.filters.to),
    periodo_anio: Number(String(props.filters.from).slice(0, 4)), corte: props.filters.cutoff, anio: props.filters.year, mes: props.filters.month,
    tercero: props.filters.contact, tercero_label: props.filters.contact_label ? `${props.filters.contact_label.identification} - ${props.filters.contact_label.name}` : '',
    producto: props.filters.product, producto_label: props.filters.product_label ? `${props.filters.product_label.code} - ${props.filters.product_label.name}` : '',
    vendedor: props.filters.seller ?? '', linea: props.filters.line ?? '', grupo: props.filters.group ?? '', centro: props.filters.cost_center ?? '',
    forma_pago: props.filters.payment ?? '', creador: props.filters.creator ?? '', bodega: props.filters.warehouse ?? '', clase: props.filters.kind ?? '',
    estado: props.filters.status ?? '', modulo: props.filters.module, nivel: props.filters.level, cuenta: props.filters.account ?? '',
    tipo: props.filters.doctype ?? '', dian: props.filters.dian ?? '', q: props.filters.q ?? '', nc: props.filters.nc ? '1' : '0', pos: props.filters.pos ? '1' : '0', impuesto: props.filters.tax ? '1' : '0',
});
const f = reactive(initial());
const draft = reactive(initial());
const panel = ref(false);

function onPeriod(target) {
    const r = periodRange(target.periodo, target.periodo_anio);
    if (r) [target.desde, target.hasta] = r;
}

function params(src = f) {
    const p = {};
    const put = (k, v) => { if (v !== '' && v !== null && v !== undefined) p[k] = v; };
    if (has('range')) Object.assign(p, { desde: src.desde, hasta: src.hasta });
    if (has('cutoff')) p.corte = src.corte;
    if (has('year')) p.anio = src.anio;
    if (has('period')) Object.assign(p, { anio: src.anio, mes: src.mes });
    if (has('contact')) put('tercero', src.tercero);
    if (has('product')) put('producto', src.producto);
    if (has('seller')) put('vendedor', src.vendedor);
    if (has('line')) put('linea', src.linea);
    if (has('group')) put('grupo', src.grupo);
    if (has('cost_center')) put('centro', src.centro);
    if (has('payment')) put('forma_pago', src.forma_pago);
    if (has('creator')) put('creador', src.creador);
    if (has('warehouse')) put('bodega', src.bodega);
    if (has('kind')) put('clase', src.clase);
    if (has('status')) put('estado', src.estado);
    if (has('module')) p.modulo = src.modulo;
    if (has('level')) p.nivel = src.nivel;
    if (has('account')) put('cuenta', src.cuenta);
    if (has('doctype')) put('tipo', src.tipo);
    if (has('dian')) put('dian', src.dian);
    if (has('q')) put('q', src.q);
    if (has('nc') && src.nc !== (props.report.defaults?.nc === false ? '0' : '1')) p.nc = src.nc;
    if (has('pos') && src.pos === '1') p.pos = '1';
    if (has('tax') && src.impuesto === '1') p.impuesto = '1';
    if (props.rows.per_page && props.rows.per_page !== 50) p.per_page = props.rows.per_page;
    return p;
}

const generate = (src = f) => router.get(`/reportes/${props.slug}`, params(src), { preserveScroll: true });
function openPanel() { Object.assign(draft, { ...f }); panel.value = true; }
function apply() { panel.value = false; generate(draft); }
function clearAll() {
    const d = { ...initial(), desde: periodRange('mes', today.getFullYear())[0], hasta: periodRange('mes', today.getFullYear())[1], periodo: 'mes', periodo_anio: today.getFullYear(),
        tercero: null, tercero_label: '', producto: null, producto_label: '', vendedor: '', linea: '', grupo: '', centro: '', forma_pago: '', creador: '', bodega: '',
        clase: '', estado: '', cuenta: '', tipo: '', dian: '', q: '', nc: '1', pos: '0', impuesto: '0' };
    panel.value = false;
    generate(d);
}

const name = (list, id, key = 'name') => (list ?? []).find((x) => String(x.id) === String(id))?.[key] ?? id;
const fmtDate = (v) => String(v).slice(0, 10).split('-').reverse().join('/');
function chipValue(k) {
    switch (k) {
        case 'range': return f.periodo === 'rango' ? `${fmtDate(f.desde)} - ${fmtDate(f.hasta)}` : f.periodo_anio === today.getFullYear() ? PERIODS[f.periodo] : `${PERIODS[f.periodo]} ${f.periodo_anio}`;
        case 'cutoff': return fmtDate(f.corte);
        case 'year': return f.anio;
        case 'contact': return f.tercero ? f.tercero_label : null;
        case 'product': return f.producto ? f.producto_label : null;
        case 'seller': return f.vendedor ? name(props.options.sellers, f.vendedor) : null;
        case 'line': return f.linea ? name(props.options.lines, f.linea) : null;
        case 'group': return f.grupo ? name(props.options.groups, f.grupo) : null;
        case 'cost_center': return f.centro ? name(props.options.cost_centers, f.centro) : null;
        case 'payment': return f.forma_pago ? name(props.options.payments, f.forma_pago) : null;
        case 'creator': return f.creador ? name(props.options.creators, f.creador) : null;
        case 'warehouse': return f.bodega ? name(props.options.warehouses, f.bodega) : null;
        case 'kind': return f.clase ? props.options.kinds[f.clase] ?? f.clase : null;
        case 'status': return f.estado ? props.options.statuses[f.estado] ?? f.estado : null;
        case 'module': return moduleLabels.value[f.modulo === 'compras' ? 'compras' : 'ventas'].replace(/ \(.*\)$/, (m) => (props.report.module_labels ? m : ''));
        case 'level': return ['Clase', 'Grupo', 'Cuenta', 'Subcuenta', 'Auxiliar'][f.nivel - 1];
        case 'account': return f.cuenta || null;
        case 'doctype': return f.tipo || null;
        case 'dian': return f.dian ? { Accepted: 'Aceptado', Rejected: 'Rechazado', Pending: 'Pendiente' }[f.dian] : null;
        case 'q': return f.q || null;
        case 'nc': return f.nc === '1' ? 'Sí' : 'No';
        case 'pos': return f.pos === '1' ? 'Sí' : 'No';
        case 'tax': return f.impuesto === '1' ? 'Sí' : 'No';
    }
    return null;
}
const moduleLabels = computed(() => props.report.module_labels ?? { ventas: 'Cuentas por cobrar (clientes)', compras: 'Cuentas por pagar (proveedores)' });
const dirty = computed(() => chips.value.some((c) => !(c.k === 'module' && f.modulo !== 'compras')));
const chips = computed(() => extra.value.map((k) => ({ k, v: chipValue(k) })).filter((c) => c.v !== null && c.v !== ''));
const RESET = { range: null, contact: { tercero: null, tercero_label: '' }, product: { producto: null, producto_label: '' }, seller: { vendedor: '' }, line: { linea: '' },
    group: { grupo: '' }, cost_center: { centro: '' }, payment: { forma_pago: '' }, creator: { creador: '' }, warehouse: { bodega: '' }, kind: { clase: '' }, status: { estado: '' },
    account: { cuenta: '' }, doctype: { tipo: '' }, dian: { dian: '' }, q: { q: '' }, nc: { nc: '1' }, pos: { pos: '0' }, tax: { impuesto: '0' } };
function removeChip(k) {
    const d = { ...f };
    if (k === 'range') Object.assign(d, { periodo: 'mes', periodo_anio: today.getFullYear(), desde: periodRange('mes', today.getFullYear())[0], hasta: periodRange('mes', today.getFullYear())[1] });
    else if (RESET[k]) Object.assign(d, RESET[k]);
    else return;
    generate(d);
}
const removable = (k) => k === 'range' || !!RESET[k];

function download(format) {
    window.location.href = `/reportes/${props.slug}?${new URLSearchParams({ ...params(), formato: format })}`;
}

const data = computed(() => props.rows.data ?? []);
const colKey = `nubicta.report.columns.${props.slug}`;
const hidden = ref(props.report.column_picker ? JSON.parse(localStorage.getItem(colKey) ?? 'null') ?? props.report.hidden ?? [] : props.report.hidden ?? []);
const pickerOpen = ref(false);
function toggleColumn(k) {
    hidden.value = hidden.value.includes(k) ? hidden.value.filter((x) => x !== k) : [...hidden.value, k];
    localStorage.setItem(colKey, JSON.stringify(hidden.value));
}
const columns = computed(() => Object.entries(props.report.columns).filter(([k]) => !hidden.value.includes(k)));
const isPaginator = computed(() => props.rows.last_page !== undefined);
const num = (v, d = 2) => (Math.abs(Number(v)) < 0.005 ? 0 : Number(v)).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
function fmt(v, type) {
    if (v === null || v === undefined || v === '') return type === 'text' ? '-' : '';
    if (type === 'money' || type === 'qty') return num(v);
    if (type === 'cost') return num(v, 6);
    if (type === 'pct') return `${num(v)} %`;
    if (type === 'int' || type === 'count') return Number(v).toLocaleString('en-US');
    if (type === 'date') return fmtDate(v);
    return v;
}
const numeric = (t) => ['money', 'qty', 'cost', 'pct', 'int'].includes(t);
function link(k, r) {
    const tpl = props.report.links?.[k];
    if (!tpl) return null;
    if (tpl.startsWith('@')) return r[tpl.slice(1)] || null;
    let missing = false;
    const url = tpl.replace(/\{(\??)(\w+)\}/g, (_, optional, key) => {
        const v = key === 'from' ? f.desde : key === 'to' ? f.hasta : key === 'seller' ? f.vendedor : r[key];
        if (v === null || v === undefined || v === '') {
            if (!optional) missing = true;
            return '';
        }
        return encodeURIComponent(v);
    });
    return missing ? null : url;
}

const p = computed(() => props.rows);
function go(page, perPage = p.value.per_page) {
    if (page < 1 || page > p.value.last_page) return;
    router.get(`/reportes/${props.slug}`, { ...params(), page, per_page: perPage }, { preserveScroll: true });
}

const favKey = 'nubicta.report.favorites';
const favorites = ref(JSON.parse(localStorage.getItem(favKey) || '[]'));
const isFav = computed(() => favorites.value.includes(props.slug));
function toggleFav() {
    favorites.value = isFav.value ? favorites.value.filter((s) => s !== props.slug) : [...favorites.value, props.slug];
    localStorage.setItem(favKey, JSON.stringify(favorites.value));
}
</script>

<template>
    <Head :title="report.title" />
    <div class="-mx-3 md:-mx-[22px] -mt-[18px] mb-[16px] flex h-[62px] items-center gap-[14px] border-b border-[#e3e7ea] bg-white px-[30px]">
        <Link href="/reportes" class="text-[17px] text-primary" aria-label="Volver"><Icon icon="arrow-left" /></Link>
        <h1 class="text-[21px] font-semibold text-ink">{{ report.title }}</h1>
        <button type="button" class="text-[17px] text-[#f5b301]" :title="isFav ? 'Quitar de favoritos' : 'Agregar a favoritos'" @click="toggleFav">
            <Icon v-if="isFav" icon="star" />
            <svg v-else viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 2.8l2.8 5.8 6.4.9-4.6 4.5 1.1 6.3L12 17.3l-5.7 3 1.1-6.3-4.6-4.5 6.4-.9z" /></svg>
        </button>
    </div>
    <p v-if="report.note && report.banner" class="mb-[12px] flex items-center gap-[10px] rounded-[4px] border border-[#bae6fd] bg-[#f0f9ff] px-[14px] py-[9px] text-[12.5px] text-ink"><Icon icon="circle-info" class="text-primary" /> {{ report.note }}</p>
    <p v-else-if="report.note" class="mb-[10px] text-[12px] text-muted"><Icon icon="circle-info" class="text-primary" /> {{ report.note }}</p>

    <section class="s-card overflow-hidden">
        <div class="flex items-end gap-[12px] px-[14px] pt-[14px]">
            <div v-for="k in primary" :key="k" :class="k === 'period' ? 'w-[280px]' : 'w-[214px]'">
                <span class="mb-[4px] block text-[12px] text-[#5d6b78]">{{ label(k) }}</span>
                <DocSearch v-if="k === 'contact'" v-model="f.tercero" v-model:label="f.tercero_label" url="/api/buscar/terceros" input-class="s-input w-full" placeholder="Buscar" @update:model-value="generate()" />
                <DocSearch v-else-if="k === 'product'" v-model="f.producto" v-model:label="f.producto_label" url="/api/buscar/productos" input-class="s-input w-full" placeholder="Buscar" @update:model-value="generate()" />
                <select v-else-if="k === 'seller'" v-model="f.vendedor" class="s-input w-full" @change="generate()"><option value="">Buscar</option><option v-for="s in options.sellers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                <select v-else-if="k === 'group'" v-model="f.grupo" class="s-input w-full" @change="generate()"><option value="">Seleccione una opción</option><option v-for="g in options.groups" :key="g.id" :value="g.id">{{ g.name }}</option></select>
                <select v-else-if="k === 'cost_center'" v-model="f.centro" class="s-input w-full" @change="generate()"><option value="">Buscar</option><option v-for="c in options.cost_centers" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option></select>
                <select v-else-if="k === 'module'" v-model="f.modulo" class="s-input w-full" @change="generate()"><option value="ventas">{{ moduleLabels.ventas }}</option><option value="compras">{{ moduleLabels.compras }}</option></select>
                <input v-else-if="k === 'q'" v-model="f.q" class="s-input w-full" :placeholder="report.q_placeholder || 'Buscar'" @keyup.enter="generate()" />
                <select v-else-if="k === 'kind'" v-model="f.clase" class="s-input w-full" @change="generate()"><option v-if="!('todos' in options.kinds)" value="">Todos</option><option v-for="(t, v) in options.kinds" :key="v" :value="v">{{ t }}</option></select>
                <select v-else-if="k === 'creator'" v-model="f.creador" class="s-input w-full" @change="generate()"><option value="">Buscar</option><option v-for="u in options.creators" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                <select v-else-if="k === 'status'" v-model="f.estado" class="s-input w-full" @change="generate()"><option value="">Todos</option><option v-for="(t, v) in options.statuses" :key="v" :value="v">{{ t }}</option></select>
                <input v-else-if="k === 'account'" v-model="f.cuenta" class="s-input w-full" placeholder="Buscar" @keyup.enter="generate()" />
                <input v-else-if="k === 'cutoff'" v-model="f.corte" type="date" class="s-input w-full" @change="generate()" />
                <select v-else-if="k === 'year'" v-model="f.anio" class="s-input w-full" @change="generate()"><option v-for="y in options.years" :key="y" :value="y">{{ y }}</option></select>
                <div v-else-if="k === 'period'" class="grid grid-cols-[100px_1fr] gap-[8px]">
                    <select v-model="f.anio" class="s-input w-full" @change="generate()"><option v-for="y in options.years" :key="y" :value="y">{{ y }}</option></select>
                    <select v-model="f.mes" class="s-input w-full" @change="generate()"><option v-for="(m, i) in MONTHS" :key="m" :value="i + 1">{{ m }}</option></select>
                </div>
            </div>
            <button v-if="extra.length" type="button" class="relative mb-[1px] inline-flex h-[32px] items-center gap-[6px] rounded-[4px] border border-primary px-[10px] text-[12.5px] text-primary hover:bg-primary-light" @click="openPanel">
                <Icon icon="sliders" /> Más filtros
                <span v-if="chips.length" class="absolute -right-[7px] -top-[8px] grid h-[16px] min-w-[16px] place-items-center rounded-full bg-[#e5484d] px-[3px] text-[10px] font-bold text-white">{{ chips.length }}</span>
            </button>
            <div v-if="report.column_picker" class="relative ml-auto mb-[1px]">
                <button type="button" class="inline-flex h-[32px] items-center gap-[6px] px-[6px] text-[12.5px] text-primary" @click="pickerOpen = !pickerOpen"><Icon icon="table-columns" /> Columnas</button>
                <div v-if="pickerOpen" class="absolute right-0 z-30 mt-[4px] w-[220px] rounded-[4px] border border-line bg-white p-[8px] shadow-lg">
                    <label v-for="(c, k) in report.columns" :key="k" class="flex cursor-pointer items-center gap-[8px] px-[6px] py-[4px] text-[13px]">
                        <input type="checkbox" :checked="!hidden.includes(k)" @change="toggleColumn(k)" /> {{ c[0] }}
                    </label>
                </div>
            </div>
            <button v-if="report.refresh" type="button" class="s-btn ml-auto mb-[1px] bg-primary text-white" @click="generate()">Actualizar</button>
            <DownloadMenu v-else :class="report.column_picker ? 'mb-[1px]' : 'ml-auto mb-[1px]'" button-class="inline-flex h-[32px] items-center gap-[6px] rounded-[4px] border border-primary px-[10px] text-[12.5px] text-primary hover:bg-primary-light" @download="download" />
        </div>
        <div class="flex min-h-[38px] flex-wrap items-center gap-[6px] px-[14px] py-[8px] text-[12px]">
            <span v-for="c in chips" :key="c.k" class="inline-flex items-center gap-[6px] rounded-full border border-[#c9ced3] bg-[#f7f9fa] py-[2px] pl-[8px] pr-[4px] text-[#4a5561]">
                {{ label(c.k) }}: {{ c.v }}
                <button v-if="removable(c.k)" type="button" class="grid h-[14px] w-[14px] place-items-center rounded-full bg-[#9aa5ae] text-[9px] text-white" :aria-label="`Quitar ${label(c.k)}`" @click="removeChip(c.k)"><Icon icon="xmark" /></button>
            </span>
            <button v-if="chips.length" type="button" class="ml-[4px] disabled:text-[#c3c9ce]" :class="dirty ? 'text-primary' : ''" :disabled="!dirty" @click="clearAll">Limpiar filtros</button>
        </div>

        <div class="max-h-[calc(100vh-290px)] overflow-auto border-t border-[#e3e7ea]">
            <table class="w-full border-collapse text-[12.5px]">
                <thead class="sticky top-0 z-10 bg-white">
                    <tr>
                        <th v-for="([k, c], i) in columns" :key="k" class="whitespace-nowrap border-b border-[#e3e7ea] px-[12px] py-[11px] text-left font-semibold text-[#2d3a45]" :class="{ 'text-right': numeric(c[1]), 'shadow-[inset_1px_0_0_#c9ced3]': i > 0 }">{{ c[0] }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in data" :key="i" class="hover:bg-[#f0f9ff]" :class="{ 'font-bold': r.bold }">
                        <td v-for="[k, c] in columns" :key="k" class="truncate border-b border-[#edf0f2] px-[12px] py-[9px]" :style="{ maxWidth: report.narrow?.includes(k) ? '120px' : '260px' }" :class="{ 'text-right whitespace-nowrap': numeric(c[1]) }" :title="c[1] === 'text' ? r[k] : null">
                            <Link v-if="link(k, r)" :href="link(k, r)" class="text-primary hover:underline">{{ fmt(r[k], c[1]) }}</Link>
                            <template v-else>{{ fmt(r[k], c[1]) }}</template>
                        </td>
                    </tr>
                    <tr v-if="!data.length"><td :colspan="columns.length" class="py-[40px] text-center text-muted">No hay información para los filtros seleccionados</td></tr>
                </tbody>
                <tfoot v-if="totals && data.length" class="sticky bottom-0 bg-white">
                    <tr class="font-bold">
                        <td v-for="([k, c], idx) in columns" :key="k" class="whitespace-nowrap border-t border-[#c9ced3] px-[12px] py-[10px]" :class="{ 'text-right': numeric(c[1]) }">
                            {{ report.totals.includes(k) ? fmt(totals[k], c[1]) : idx === 0 ? 'Total general' : '' }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div v-if="isPaginator" class="flex items-center justify-end gap-[24px] border-t border-[#e3e7ea] px-[20px] py-[10px] text-[13px]">
            <span class="flex items-center gap-[8px]">Items por página
                <select class="s-input h-[30px] w-[72px] px-2" :value="p.per_page" @change="go(1, $event.target.value)"><option v-for="n in [10, 20, 50, 100, 500]" :key="n" :value="n">{{ n }}</option></select>
            </span>
            <span><b>{{ p.from ?? 0 }}</b> a <b>{{ p.to ?? 0 }}</b> de <b>{{ p.total }}</b></span>
            <span class="flex items-center gap-[12px] text-muted">
                <button type="button" :disabled="p.current_page <= 1" class="disabled:opacity-40" aria-label="Primera página" @click="go(1)"><Icon icon="angles-left" /></button>
                <button type="button" :disabled="p.current_page <= 1" class="disabled:opacity-40" aria-label="Página anterior" @click="go(p.current_page - 1)"><Icon icon="chevron-left" /></button>
                <span class="text-ink">Página <b>{{ p.current_page }}</b> de <b>{{ p.last_page }}</b></span>
                <button type="button" :disabled="p.current_page >= p.last_page" class="disabled:opacity-40" aria-label="Página siguiente" @click="go(p.current_page + 1)"><Icon icon="chevron-right" /></button>
                <button type="button" :disabled="p.current_page >= p.last_page" class="disabled:opacity-40" aria-label="Última página" @click="go(p.last_page)"><Icon icon="angles-right" /></button>
            </span>
        </div>
    </section>

    <Teleport to="body">
        <div v-if="panel" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="panel = false">
            <aside class="flex h-full w-[440px] max-w-full flex-col bg-white shadow-xl">
                <div class="flex h-[64px] items-center justify-between border-b border-[#edf0f2] px-[24px]">
                    <h2 class="text-[16px] font-semibold text-ink">Más filtros</h2>
                    <button type="button" class="text-[16px] text-[#5d6b78]" aria-label="Cerrar" @click="panel = false"><Icon icon="xmark" /></button>
                </div>
                <div class="flex-1 space-y-[18px] overflow-y-auto px-[24px] py-[20px] text-[13px]">
                    <div v-for="k in extra" :key="k">
                        <span class="mb-[6px] block text-[12.5px] text-[#5d6b78]">{{ label(k) }}</span>
                        <template v-if="k === 'range'">
                            <div class="grid grid-cols-2 gap-[12px]">
                                <select v-model.number="draft.periodo_anio" class="s-input w-full" @change="onPeriod(draft)"><option v-for="y in options.years" :key="y" :value="y">{{ y }}</option></select>
                                <select v-model="draft.periodo" class="s-input w-full" @change="onPeriod(draft)"><option v-for="(t, v) in PERIODS" :key="v" :value="v">{{ t }}</option></select>
                                <input v-model="draft.desde" type="date" class="s-input w-full" @change="draft.periodo = 'rango'" />
                                <input v-model="draft.hasta" type="date" class="s-input w-full" @change="draft.periodo = 'rango'" />
                            </div>
                        </template>
                        <input v-else-if="k === 'cutoff'" v-model="draft.corte" type="date" class="s-input w-full" />
                        <select v-else-if="k === 'year'" v-model="draft.anio" class="s-input w-full"><option v-for="y in options.years" :key="y" :value="y">{{ y }}</option></select>
                        <DocSearch v-else-if="k === 'contact'" v-model="draft.tercero" v-model:label="draft.tercero_label" url="/api/buscar/terceros" input-class="s-input w-full" placeholder="Buscar" />
                        <DocSearch v-else-if="k === 'product'" v-model="draft.producto" v-model:label="draft.producto_label" url="/api/buscar/productos" input-class="s-input w-full" placeholder="Buscar" />
                        <select v-else-if="k === 'seller'" v-model="draft.vendedor" class="s-input w-full"><option value="">Todos</option><option v-for="s in options.sellers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                        <select v-else-if="k === 'line'" v-model="draft.linea" class="s-input w-full"><option value="">Todas</option><option v-for="l in options.lines" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }}</option></select>
                        <select v-else-if="k === 'group'" v-model="draft.grupo" class="s-input w-full"><option value="">Todos</option><option v-for="g in options.groups" :key="g.id" :value="g.id">{{ g.name }}</option></select>
                        <select v-else-if="k === 'cost_center'" v-model="draft.centro" class="s-input w-full"><option value="">Todos</option><option v-for="c in options.cost_centers" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option></select>
                        <select v-else-if="k === 'payment'" v-model="draft.forma_pago" class="s-input w-full"><option value="">Todas</option><option v-for="m in options.payments" :key="m.id" :value="m.id">{{ m.name }}</option></select>
                        <select v-else-if="k === 'creator'" v-model="draft.creador" class="s-input w-full"><option value="">Todos</option><option v-for="u in options.creators" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                        <select v-else-if="k === 'warehouse'" v-model="draft.bodega" class="s-input w-full"><option value="">Todas</option><option v-for="w in options.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                        <select v-else-if="k === 'kind'" v-model="draft.clase" class="s-input w-full"><option v-if="!('todos' in options.kinds)" value="">Todos</option><option v-for="(t, v) in options.kinds" :key="v" :value="v">{{ t }}</option></select>
                        <select v-else-if="k === 'status'" v-model="draft.estado" class="s-input w-full"><option v-if="!('todos' in options.statuses)" value="">Todos</option><option v-for="(t, v) in options.statuses" :key="v" :value="v">{{ t }}</option></select>
                        <select v-else-if="k === 'module'" v-model="draft.modulo" class="s-input w-full"><option value="ventas">{{ moduleLabels.ventas }}</option><option value="compras">{{ moduleLabels.compras }}</option></select>
                        <select v-else-if="k === 'level'" v-model="draft.nivel" class="s-input w-full"><option v-for="(n, i) in ['Clase', 'Grupo', 'Cuenta', 'Subcuenta', 'Auxiliar']" :key="n" :value="i + 1">{{ n }}</option></select>
                        <input v-else-if="k === 'account'" v-model="draft.cuenta" class="s-input w-full" placeholder="Ej: 1105" />
                        <select v-else-if="k === 'doctype'" v-model="draft.tipo" class="s-input w-full"><option value="">Todos</option><option v-for="t in options.doctypes" :key="t" :value="t">{{ t }}</option></select>
                        <select v-else-if="k === 'dian'" v-model="draft.dian" class="s-input w-full"><option value="">Todos</option><option value="Accepted">Aceptado</option><option value="Rejected">Rechazado</option><option value="Pending">Pendiente</option></select>
                        <input v-else-if="k === 'q'" v-model="draft.q" class="s-input w-full" placeholder="Buscar" />
                        <select v-else-if="k === 'nc'" v-model="draft.nc" class="s-input w-full"><option value="1">Sí</option><option value="0">No</option></select>
                        <select v-else-if="k === 'pos'" v-model="draft.pos" class="s-input w-full"><option value="0">No</option><option value="1">Sí</option></select>
                        <select v-else-if="k === 'tax'" v-model="draft.impuesto" class="s-input w-full"><option value="0">No</option><option value="1">Sí</option></select>
                    </div>
                </div>
                <div class="flex h-[64px] items-center gap-[10px] border-t border-[#edf0f2] px-[24px]">
                    <button type="button" class="text-[13px] text-primary" @click="panel = false">Cancelar</button>
                    <button type="button" class="s-btn ml-auto border border-[#c9ced3] bg-white text-[#5d6b78] disabled:opacity-50" :disabled="!dirty" @click="clearAll">Limpiar filtros</button>
                    <button type="button" class="s-btn s-btn-primary" @click="apply">Aplicar filtros</button>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
