<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import NumInput from '../../Components/Forms/NumInput.vue';
import { confirmDialog } from '../../composables/dialog';

/* Formulario comercial de Siigo: factura de venta, notas, cotizacion, remision, compras, documento soporte y orden de compra. */
const props = defineProps({
    form: Object, docTypes: Array, paymentMethods: Array, charges: Array, withholdings: Array,
    sellers: Array, defaultSeller: { type: Number, default: null }, costCenters: Array, warehouses: Array, priceLists: { type: Array, default: () => [] }, today: String, initial: { type: Object, default: null },
});
const f = props.form;
const sale = !f.purchase;
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const round = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
/* En Siigo la tarifa de ReteICA es por mil (6 = 6/1000); las demas son porcentaje. */
const rateOf = (t) => (t ? Number(t.percentage) / (t.type === 'ReteICA' ? 1000 : 100) : 0);

const ITEM_URL = { Producto: '/api/buscar/productos', 'Cuenta contable': '/api/cuentas', 'Activo fijo': '/api/buscar/activos' };
const PRODUCT_COLUMNS = [['code', 'Código'], ['name', 'Descripción'], ['reference', 'Referencia de fábrica'], ['status', 'Estado']];
const CONTACT_COLUMNS = [['name', 'Nombre completo'], ['identification', 'Identificación'], ['branch', 'Sucursal']];
const PAYMENT_COLUMNS = [['name', 'Nombre'], ['account', 'Cuenta contable']];
const RANGES = [['0', 'Hoy'], ['15', 'a 15 días'], ['30', 'a 30 días'], ['45', 'a 45 días'], ['60', 'a 60 días'], ['90', 'a 90 días'], ['120', 'a 120 días'], ['360', 'a 360 días']];

const blankItem = () => ({ type: 'Producto', item_id: null, label: '', description: '', quantity: 1, price: 0, discount: 0, tax_id: null, withholding_id: null, prices: null, unit: '', stock: null, stock_control: false, role: 'mandatario', free: false, tax_code: null });
const blankPrincipal = () => ({ contact_id: null, label: '', identificacion: '', dv: '', nombre: '', ciudad: '', codigo_ciudad: '', regimen: '', correo: '', direccion: '', logo_url: '', telefono: '', whatsapp: '', sitio: '', atencion: '', cuentas: '' });
const blankPayment = () => ({ method_id: null, label: '', value: 0, range: '0', due_date: null });
const defaultType = props.docTypes.length === 1 || !f.purchase ? ((f.dian_reason && props.docTypes.find((t) => t.electronic)) || props.docTypes[0])?.id ?? null : null;

const doc = useForm({
    action: '',
    document_type: defaultType,
    number: null,
    date: props.today,
    related_id: null, related_label: '',
    contact_id: null, contact_label: '', person_id: null,
    seller_id: null, cost_center_id: null, reason: '', warehouse_id: null, due_date: null,
    supplier_prefix: f.supplier_number ? f.doc : '', supplier_number: '',
    checks: Object.fromEntries((f.checks ?? []).map((c) => [c, false])),
    header_text: '', footer_text: '', observations: '',
    items: [blankItem()],
    mandate: false,
    payment_form: '',
    principal: blankPrincipal(),
    use_external: false,
    external_invoice: { numero: '', cufe: '', fecha: '' },
    credit: false,
    payments: [blankPayment()],
    summary_retentions: Object.fromEntries((f.retentions ?? []).map((r) => [r, null])),
    total: 0,
    ...(props.initial ?? {}),
    checks: { ...Object.fromEntries((f.checks ?? []).map((c) => [c, false])), ...(props.initial?.checks ?? {}) },
    summary_retentions: { ...Object.fromEntries((f.retentions ?? []).map((r) => [r, null])), ...(props.initial?.summary_retentions ?? {}) },
});
const inlineErrorFields = ['related_id', 'document_type', 'date', 'contact_id', 'reason', 'items'];
const unhandledErrors = computed(() => Object.entries(doc.errors)
    .filter(([field]) => !inlineErrorFields.includes(field))
    .map(([field, message]) => ({ field, message })));
if (props.initial) {
    doc.items.push(blankItem());
    if (!doc.payments.length) doc.payments.push(blankPayment());
}
const methodById = (id) => props.paymentMethods.find((m) => m.id === id);
doc.payments.forEach((p) => { p.label ||= methodById(p.method_id)?.label ?? ''; p.range ??= '0'; });

const people = ref([]);
const advanceBalance = ref(null);
const advanceBalanceError = ref('');
const editNumber = ref(false);
const attachment = ref(null);
const editing = ref(0);
const sellerOptions = computed(() => props.sellers.map((s) => ({ id: s.id, label: s.name })));
const sellerLabel = ref(props.sellers.find((s) => s.id === doc.seller_id)?.name ?? '');
const percentDiscount = computed(() => !f.purchase || doc.checks['Descuento en porcentaje']);
const taxIncluded = computed(() => !!(doc.checks['IVA / Impoconsumo incluido'] || doc.checks['Impuestos cargo incluidos']));
const currentType = computed(() => props.docTypes.find((d) => d.id === doc.document_type));
const partyLabel = computed(() => (f.party === 'proveedor' ? (f.related || f.classic ? 'Proveedor' : 'Proveedores') : (['RM', 'ND'].includes(f.doc) ? 'Clientes' : 'Cliente')));
const taxById = (id, list) => list.find((t) => t.id === id);

const mandateDoc = computed(() => f.doc === 'FV' || f.doc === 'NC');
function lineCalc(i) {
    const gross = Number(i.quantity || 0) * Number(i.price || 0);
    const disc = percentDiscount.value ? (gross * Number(i.discount || 0)) / 100 : Number(i.discount || 0);
    if (i.tax_code === 'ZZ') {
        const net = gross - disc;
        return { gross, disc, base: round(net), tax: 0, charge: null, wh: null, withholding: 0 };
    }
    const charge = taxById(i.tax_id, props.charges);
    const rate = rateOf(charge);
    const net = gross - disc;
    const [base, total] = taxIncluded.value ? [round(net / (1 + rate)), round(net)] : [round(net), round(net * (1 + rate))];
    const wh = taxById(i.withholding_id, props.withholdings);

    return { gross, disc, base, tax: round(total - base), charge, wh, withholding: base * rateOf(wh) };
}

const totals = computed(() => {
    const lines = doc.items.map(lineCalc);
    const sum = (k) => lines.reduce((a, l) => a + l[k], 0);
    const group = (key) => Object.values(lines.reduce((acc, l) => {
        if (l[key]) {
            const t = l[key];
            acc[t.id] ??= { name: t.name, value: 0 };
            acc[t.id].value += key === 'charge' ? l.tax : l.withholding;
        }
        return acc;
    }, {}));
    const subtotal = sum('base');
    const ivaTotal = lines.filter((l) => l.charge?.type === 'IVA').reduce((a, l) => a + l.tax, 0);
    const summary = Object.entries(doc.summary_retentions).map(([kind, id]) => {
        const t = taxById(id, props.withholdings);
        return { kind, value: t ? round((kind === 'ReteIVA' ? ivaTotal : subtotal) * rateOf(t)) : 0 };
    });
    const charges = group('charge').filter((c) => c.value > 0);
    const withholdings = group('wh').filter((w) => w.value > 0);
    const net = subtotal + sum('tax') - sum('withholding') - summary.reduce((a, s) => a + s.value, 0);
    const paid = doc.payment_form === 'contado' || (f.credit_toggle && doc.credit) ? net : doc.payments.reduce((a, p) => a + Number(p.value || 0), 0);

    return { gross: sum('gross'), disc: sum('disc'), subtotal, charges, withholdings, summary, net: round(net), paid: round(paid) };
});

const summaryOptions = (kind) => props.withholdings.filter((w) => w.type === kind);
const byRate = (a, b) => b.percentage - a.percentage;
const itemCharges = computed(() => (f.sorted_taxes
    ? ['IVA', 'Impoconsumo'].flatMap((type) => props.charges.filter((c) => c.type === type && (type === 'IVA' || f.sorted_taxes === 'all' || Number(c.percentage) > 0)).sort(byRate))
    : props.charges));
const itemWithholdings = computed(() => {
    const list = props.withholdings.filter((w) => (f.item_withholdings ? f.item_withholdings.includes(w.type) : w.type !== 'ReteICA' || !f.retentions?.includes('ReteICA')));
    return f.sorted_taxes ? [...list].sort(byRate) : list;
});
const itemTypes = f.item_types ?? Object.fromEntries(Object.keys(ITEM_URL).map((t) => [t, t]));
const saleWithholdings = computed(() => props.withholdings.filter((w) => w.type === 'Retefuente'));

function addDays(date, days) {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + Number(days));
    return d.toISOString().slice(0, 10);
}

/* al elegir el cliente se llenan el contacto y el vendedor asignado. */
function pickContact(c, keep = false) {
    people.value = c?.people ?? [];
    if (keep) return;
    doc.person_id = people.value[0]?.id ?? null;
    const seller = props.sellers.find((s) => s.id === (c?.seller_id ?? props.defaultSeller));
    if (c && seller) {
        doc.seller_id = seller.id;
        sellerLabel.value = seller.name;
    }
}

watch(() => totals.value.net, (net) => {
    if (doc.payments.length === 1 && doc.payments[0].method_id) doc.payments[0].value = net;
});

watch(() => doc.date, () => doc.payments.forEach((p) => { if (methodById(p.method_id)?.credit) p.due_date = addDays(doc.date, p.range); }));

watch(() => (sale && f.doc === 'FV') || (f.purchase && f.doc === 'FC') ? doc.contact_id : null, async (contactId, _, onCleanup) => {
    advanceBalance.value = null;
    advanceBalanceError.value = '';
    if (!contactId) return;

    const controller = new AbortController();
    onCleanup(() => controller.abort());
    try {
        const module = sale ? 'ventas' : 'compras';
        const response = await fetch(`/api/buscar/anticipos?modulo=${module}&contact=${contactId}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        if (!response.ok) throw new Error('No fue posible consultar el saldo de anticipos.');
        const result = await response.json();
        const balance = Number(result.advance_balance);
        if (!Number.isFinite(balance)) throw new Error('La respuesta del saldo de anticipos no es válida.');
        advanceBalance.value = round(balance);
    } catch (error) {
        if (error.name === 'AbortError') return;
        advanceBalanceError.value = 'No se pudo consultar el saldo de anticipos. Intenta de nuevo.';
    }
}, { immediate: true });

async function loadPeople() {
    if (!doc.contact_id) return;
    const res = await fetch(`/api/buscar/terceros?id=${doc.contact_id}`, { headers: { Accept: 'application/json' } });
    const [c] = await res.json();
    if (c) pickContact(c, true);
}

onMounted(async () => {
    if (f.related && doc.related_id && !doc.items.some((i) => i.item_id)) return pickRelated({ id: doc.related_id, contact_id: doc.contact_id, contact_label: doc.contact_label });
    await loadPeople();
});

/* La nota toma de la factura el cliente, su contacto, el vendedor, las formas de pago y los items. */
async function pickRelated(d) {
    if (!d) return;
    doc.contact_id = d.contact_id;
    doc.contact_label = d.contact_label;
    const json = (url) => fetch(url, { headers: { Accept: 'application/json' } }).then((r) => r.json());
    const [head, items] = await Promise.all([
        json(`/api/buscar/documentos/${d.id}/encabezado?modulo=${f.related}`),
        json(`/api/buscar/documentos/${d.id}/items?modulo=${f.related}`),
    ]);
    if (head.contact_id) {
        doc.contact_id = head.contact_id;
        doc.contact_label = head.contact_label;
    }
    await loadPeople();
    if (head.person_id && people.value.some((p) => p.id === head.person_id)) doc.person_id = head.person_id;
    const seller = props.sellers.find((s) => s.id === head.seller_id);
    if (seller) {
        doc.seller_id = seller.id;
        sellerLabel.value = seller.name;
    }
    if ('cost_center_id' in head && head.cost_center_id) doc.cost_center_id = head.cost_center_id;
    if (head.principal) {
        doc.mandate = true;
        const cuentas = Array.isArray(head.principal.cuentas) ? head.principal.cuentas.map((c) => [c.banco, c.tipo, c.numero].filter(Boolean).join(';')).join('\n') : (head.principal.cuentas || '');
        doc.principal = { ...blankPrincipal(), ...head.principal, cuentas };
    }
    if (head.payment_form === 'contado') doc.payment_form = 'contado';
    if (f.credit_toggle && head.credit) {
        doc.credit = true;
    } else if (head.payments?.length) {
        doc.payments = head.payments.filter((p) => methodById(p.method_id)).map((p) => ({ ...blankPayment(), method_id: p.method_id, label: methodById(p.method_id).label, value: p.value, due_date: p.due_date }));
        if (!doc.payments.length) doc.payments = [blankPayment()];
    }
    doc.items = items.length
        ? items.map((i) => ({ ...blankItem(), type: i.free ? 'Libre' : i.type, item_id: i.free ? null : (i.product_id ?? i.account_id), label: i.label, description: i.description, quantity: i.quantity, price: i.price, discount: i.discount, tax_id: i.tax_code === 'ZZ' ? null : i.tax_id, role: i.role || 'mandatario', free: !!i.free, tax_code: i.tax_code === 'ZZ' ? 'ZZ' : null }))
        : [blankItem()];
    if (sale) doc.items.push(blankItem());
}

/* Si el precio del producto ya trae el impuesto y el documento no esta marcado como "incluido", se deja la base. */
function netPrice(price, taxId, included) {
    const charge = taxById(taxId, props.charges);
    if (!included || !charge || taxIncluded.value) return Number(price);
    return Math.round((Number(price) / (1 + rateOf(charge)) + Number.EPSILON) * 1e6) / 1e6;
}

/* Si el producto trae el IVA en el precio y se cambia el impuesto de la linea, la base se recalcula desde el precio de lista. */
function retax(row, taxId) {
    if (row.tax_included && row.list_price) row.price = netPrice(row.list_price, taxId, true);
}

function pickItem(row, idx, item) {
    if (!item) return;
    row.description = item.name ?? row.description;
    row.prices = item.prices ?? null;
    row.unit = item.unit ?? '';
    row.tax_included = !!item.tax_included;
    row.stock = item.stock ?? null;
    row.stock_control = !!item.stock_control;
    if (item.tax_id && !(f.purchase && row.tax_id)) row.tax_id = item.tax_id;
    if (item.withholding_id && props.withholdings.some((w) => w.id === item.withholding_id && w.type === 'Retefuente')) row.withholding_id = item.withholding_id;
    if (!f.purchase && item.price !== undefined && Number(item.price) > 0) {
        row.list_price = Number(item.price);
        row.price = netPrice(item.price, row.tax_id, item.tax_included);
    }
    if (idx === doc.items.length - 1) doc.items.push(blankItem());
}

/* Precio de otra lista (boton junto al valor unitario). */
const priceMenu = ref(null);
const priceOptions = (row) => Object.entries(row.prices ?? {}).map(([listId, value]) => ({ name: props.priceLists.find((l) => l.id === Number(listId))?.name ?? 'Lista', value }));
function pickPrice(row, value) {
    row.list_price = Number(value);
    row.price = netPrice(value, row.tax_id, row.tax_included);
    priceMenu.value = null;
}

/* Valor digitado a mano en un producto con impuesto incluido: al salir del campo se deja la base, igual que el precio de lista. */
function typedPrice(row) {
    if (!row.tax_included || row.list_price) return;
    row.list_price = Number(row.price);
    row.price = netPrice(row.price, row.tax_id, true);
}

/* Al hacer clic fuera de la linea en edicion (y fuera de sus listas), se vuelve a mostrar como texto. */
function leaveRows(e) {
    if (editing.value < 0 || taxModal.value || e.target.closest('[data-docsearch-list], [data-edit-row]')) return;
    editing.value = -1;
    priceMenu.value = null;
}
onMounted(() => document.addEventListener('pointerdown', leaveRows));
onBeforeUnmount(() => document.removeEventListener('pointerdown', leaveRows));

function setZz(row, on) {
    row.tax_code = on ? 'ZZ' : null;
    if (on) {
        row.tax_id = null;
        row.withholding_id = null;
    }
}

function addFreeLine() {
    const row = { ...blankItem(), free: true, type: 'Libre', role: doc.mandate ? 'mandante' : 'mandatario', description: '' };
    const last = doc.items[doc.items.length - 1];
    if (sale && last && !last.item_id && !last.free) doc.items.splice(doc.items.length - 1, 0, row);
    else doc.items.push(row);
    editing.value = doc.items.indexOf(row);
}

function pickPrincipal(c) {
    if (!c) return;
    doc.principal.identificacion = c.identification || '';
    doc.principal.dv = c.dv ?? '';
    doc.principal.nombre = c.name || '';
    doc.principal.correo = c.email || '';
    doc.principal.direccion = c.address || '';
    doc.principal.telefono = c.phone || '';
    doc.principal.codigo_ciudad = c.municipality_code || '';
    doc.principal.regimen = c.vat_responsible ? 'Responsable de IVA' : 'No responsable de IVA';
}

function removeItem(idx) {
    doc.items.splice(idx, 1);
    if (!doc.items.length) doc.items.push(blankItem());
    editing.value = Math.min(editing.value, doc.items.length - 1);
}

/* Ventana de impuestos de la linea (boton ⊕). */
const taxModal = ref(null);
function openTaxes(row) {
    taxModal.value = { row, tax_id: row.tax_id, withholding_id: row.withholding_id, base: lineCalc(row).base };
}
function saveTaxes() {
    if (taxModal.value.row.tax_id !== taxModal.value.tax_id) retax(taxModal.value.row, taxModal.value.tax_id);
    taxModal.value.row.tax_id = taxModal.value.tax_id;
    taxModal.value.row.withholding_id = taxModal.value.withholding_id;
    taxModal.value = null;
}
const taxLabel = (row) => (row.tax_code === 'ZZ' ? 'No aplica (ZZ)' : (taxById(row.tax_id, props.charges)?.name ?? (row.item_id || row.free ? '0' : '')));

function pickPayment(p, m) {
    p.due_date = m?.credit ? addDays(doc.date || props.today, p.range || '0') : null;
    if (!m || Number(p.value) > 0) return;
    const others = doc.payments.filter((x) => x !== p).reduce((a, x) => a + Number(x.value || 0), 0);
    p.value = round(Math.max(totals.value.net - others, 0));
}

/* Factura y remision avisan si sacan mas de lo que hay (se suma el mismo producto en todas las lineas); se puede guardar igual. */
const checksStock = sale && ['FV', 'RM'].includes(f.doc);
const requested = computed(() => doc.items.reduce((acc, r) => {
    if (r.type === 'Producto' && r.item_id && r.stock_control && r.stock !== null) acc[r.item_id] = (acc[r.item_id] ?? 0) + Number(r.quantity || 0);
    return acc;
}, {}));
const shortOf = (row) => checksStock && row.type === 'Producto' && row.item_id && row.stock_control && row.stock !== null && requested.value[row.item_id] > Number(row.stock);
const shortages = computed(() => doc.items.filter(shortOf).filter((r, i, all) => all.findIndex((x) => x.item_id === r.item_id) === i)
    .map((r) => `${r.label}${r.description ? ' - ' + r.description : ''}: disponible ${money(r.stock)}, solicitado ${money(requested.value[r.item_id])}`));

async function submit(action) {
    if (shortages.value.length && !(await confirmDialog('Productos sin saldo suficiente:\n' + shortages.value.join('\n') + '\n\nEl inventario quedará en negativo. ¿Deseas continuar?'))) {
        return;
    }
    doc.action = action;
    doc.total = totals.value.net;
    doc.transform((d) => ({
        ...d,
        attachment: attachment.value,
        payment_form: d.payment_form === 'contado' ? 'contado' : null,
        principal: d.mandate ? d.principal : null,
        external_invoice: d.use_external ? d.external_invoice : null,
        related_id: d.use_external ? null : d.related_id,
        items: d.items.filter((i) => i.item_id || i.free).map((i) => ({
            ...i,
            product_id: i.free || i.type !== 'Producto' ? null : i.item_id,
            account_id: !i.free && i.type === 'Cuenta contable' ? i.item_id : null,
            fixed_asset_id: !i.free && i.type === 'Activo fijo' ? i.item_id : null,
            role: d.mandate ? (i.role || 'mandatario') : null,
            tax_code: i.tax_code === 'ZZ' ? 'ZZ' : null,
            free: i.free ? true : null,
        })),
    })).post(f.url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="f.title" />

    <div v-if="f.dian_banner" class="mx-auto mb-[12px] flex max-w-[756px] items-start gap-[12px] rounded-[2px] border border-[#0275b3] bg-[#f0f9ff] px-[14px] py-[10px] text-[11.5px] text-[#333]">
        <Icon icon="circle-info" class="mt-[2px] text-[16px] text-[#0275b3]" />
        <p>Por recomendación de la DIAN, deberías configurar un tipo de factura electrónica como <b>Tipo 03 (Contingencia)</b> para que puedas expedir facturas de talonario o de papel en caso de inconvenientes tecnológicos con el sistema de facturación electrónica. (Art. 31 Res. 42 de 2020).
            <Link href="/configuracion/facturacion-electronica" class="font-semibold text-primary">Aprende más y cómo configurarla.</Link></p>
    </div>

    <form :class="f.classic ? 'classic-doc' : 'doc-sheet'" @submit.prevent="submit('Guardar')">
        <div :class="f.classic ? 'px-[14px] pt-[8px]' : 'doc-body'">
            <div class="flex items-center gap-[12px]">
                <h1 class="doc-title">{{ f.title }}</h1>
                <label v-if="mandateDoc" class="flex items-center gap-[6px] text-[12px]"><input v-model="doc.mandate" type="checkbox" /> Facturar por mandato</label>
                <a v-if="f.logo || f.doc === 'NC'" href="/ayuda#ventas" target="_blank" class="rounded-[2px] border border-[#ccc] px-[8px] py-[1px] text-[10px] text-primary hover:underline">Ver tutoriales</a>
            </div>
            <div v-if="unhandledErrors.length" class="mt-[12px] rounded-[2px] border border-[#e5b6b6] bg-[#fdecec] px-[12px] py-[8px] text-[12px] text-[#c62828]" role="alert">
                <p class="mb-[4px] font-semibold">No se pudo guardar el documento:</p>
                <p v-for="error in unhandledErrors" :key="error.field">{{ error.message }}</p>
            </div>
            <div v-if="f.classic" class="mt-[14px] flex justify-end gap-[8px]">
                <button v-for="b in f.buttons" :key="b" type="button" class="doc-btn doc-btn-green" :disabled="doc.processing" @click="submit(b)">{{ b }}</button>
                <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex items-center">Cancelar</Link>
            </div>
            <p v-if="f.logo" class="mt-[18px] text-[11px] font-semibold text-primary">Cambiar logo <Icon icon="cloud-arrow-up" /></p>
            <Link v-if="f.import_xml" href="/compras/importar-xml" class="mt-[18px] inline-block text-[11px] font-semibold text-primary underline"><Icon icon="file-lines" /> Importar compra o gasto desde XML o ZIP</Link>

            <!-- Encabezado -->
            <div class="mt-[30px] grid grid-cols-2 gap-x-[60px] gap-y-[10px]">
                <div class="flex flex-col gap-[10px]">
                    <div v-if="f.related" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">{{ f.related_label }}</span>
                        <div>
                            <DocSearch v-if="!doc.use_external" v-model="doc.related_id" v-model:label="doc.related_label" :url="`/api/buscar/documentos?modulo=${f.related}`" @pick="pickRelated" />
                            <div v-else class="grid gap-[4px]">
                                <input v-model="doc.external_invoice.numero" class="doc-input" placeholder="Número de la factura" />
                                <input v-model="doc.external_invoice.cufe" class="doc-input" placeholder="CUFE" />
                                <input v-model="doc.external_invoice.fecha" type="date" class="doc-input w-[150px]" />
                            </div>
                            <label v-if="f.doc === 'NC'" class="mt-[4px] flex items-center gap-[4px] text-[11px]"><input v-model="doc.use_external" type="checkbox" /> Factura de otro sistema</label>
                        </div>
                        <p v-if="doc.errors.related_id" class="col-start-2 text-[10px] text-danger">{{ doc.errors.related_id }}</p>
                    </div>
                    <div class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Tipo <Icon v-if="!f.related" icon="circle-info" class="text-[9px] text-primary" /></span>
                        <div>
                            <select v-model="doc.document_type" class="doc-input" :class="{ 'is-invalid': doc.errors.document_type }">
                                <option :value="null"></option>
                                <option v-for="t in docTypes" :key="t.id" :value="t.id">{{ t.label }}</option>
                            </select>
                            <p v-if="doc.errors.document_type" class="text-[10px] text-danger">{{ doc.errors.document_type }}</p>
                        </div>
                    </div>
                    <div v-if="!f.seller_left" class="grid grid-cols-[76px_1fr] items-start gap-[8px]" :class="{ 'order-1': !f.purchase }">
                        <span class="doc-label">Fecha de elaboración</span>
                        <div>
                            <input v-model="doc.date" type="date" class="doc-input req w-[150px]" :class="{ 'is-invalid': doc.errors.date }" />
                            <p v-if="doc.errors.date" class="text-[10px] text-danger">{{ doc.errors.date }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">{{ partyLabel }}</span>
                        <div>
                            <div class="flex items-center gap-[18px]">
                                <DocSearch v-model="doc.contact_id" v-model:label="doc.contact_label" :url="sale ? `/api/buscar/terceros?tipo=${f.party}` : '/api/buscar/terceros'" :invalid="!!doc.errors.contact_id"
                                    :columns="CONTACT_COLUMNS" :create-url="sale ? '/terceros/crear?tipo=cliente' : '/terceros/crear?tipo=proveedor'" :display="sale ? 'name' : 'label'" class="flex-1" @pick="(c) => pickContact(c)" />
                                <a v-if="sale && doc.contact_id" :href="`/terceros/${doc.contact_id}/editar`" target="_blank" class="whitespace-nowrap text-[11px] text-[#7cb342] underline">Ver perfil</a>
                            </div>
                            <p v-if="doc.errors.contact_id" class="text-[10px] text-danger">{{ doc.errors.contact_id }}</p>
                            <p v-if="sale && advanceBalance !== null && advanceBalance !== 0" class="text-[11px]" :class="advanceBalance > 0 ? 'text-[#2e7d32]' : 'text-[#c62828]'">
                                Saldo Anticipos: {{ money(advanceBalance) }}
                            </p>
                            <p v-else-if="f.purchase && f.doc === 'FC' && advanceBalance > 0" class="text-[11px] text-[#2e7d32]">
                                Saldo Anticipos: {{ money(advanceBalance) }}
                            </p>
                            <p v-if="advanceBalanceError" class="text-[10px] text-danger" role="alert">{{ advanceBalanceError }}</p>
                        </div>
                    </div>
                    <div v-if="!f.classic && (!f.related || f.party === 'cliente')" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Contacto <Icon icon="circle-info" class="text-[9px] text-primary" /></span>
                        <select v-model="doc.person_id" class="doc-input">
                            <option :value="null"></option>
                            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.email ? `${p.name} (${p.email})` : p.name }}</option>
                        </select>
                    </div>
                    <div v-if="f.seller_left" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">{{ f.seller_label ?? 'Vendedor' }}</span>
                        <DocSearch v-model="doc.seller_id" v-model:label="sellerLabel" :options="sellerOptions" />
                    </div>
                    <div v-if="f.reason && f.seller_left" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Motivo de remisión</span>
                        <select v-model="doc.reason" class="doc-input"><option value=""></option><option v-for="r in f.reason" :key="r">{{ r }}</option></select>
                    </div>
                    <div v-if="f.seller && f.related" class="order-2 grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Vendedor</span>
                        <DocSearch v-model="doc.seller_id" v-model:label="sellerLabel" :options="sellerOptions" />
                    </div>
                    <div v-for="c in f.checks ?? []" :key="c" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">{{ c }}</span>
                        <input v-model="doc.checks[c]" type="checkbox" class="mt-[2px] h-[13px] w-[13px]" />
                    </div>
                </div>

                <div class="space-y-[10px]">
                    <div class="grid grid-cols-[76px_1fr] items-start gap-[8px]" :class="{ 'mt-[35px]': f.related, 'mt-[34px]': f.classic }">
                        <span class="doc-label">Número <Icon icon="circle-info" class="text-[9px] text-primary" /></span>
                        <div class="flex items-start justify-between gap-[8px]">
                            <input v-if="editNumber || (f.purchase && !currentType)" v-model="doc.number" class="doc-input" :placeholder="currentType ? String(currentType.consecutive) : ''" />
                            <input v-else-if="f.classic" class="doc-input" placeholder="Numeración automática" disabled />
                            <span v-else-if="f.number_text" class="text-[11.5px]">{{ f.number_text }}</span>
                            <span v-else-if="currentType" class="text-[11.5px]">{{ currentType.consecutive }} (Numeración Automática)</span>
                            <button v-if="f.logo" type="button" class="doc-link whitespace-nowrap" @click="editNumber = !editNumber">Editar consecutivo</button>
                        </div>
                    </div>
                    <div v-if="f.classic" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Contacto <Icon icon="circle-info" class="text-[9px] text-primary" /></span>
                        <select v-model="doc.person_id" class="doc-input">
                            <option :value="null"></option>
                            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.email ? `${p.name} (${p.email})` : p.name }}</option>
                        </select>
                    </div>
                    <div v-if="f.supplier_number || f.supplier_number_readonly" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">{{ typeof f.supplier_number === 'string' ? f.supplier_number : 'No. factura proveedor' }}</span>
                        <div v-if="f.supplier_number" class="grid grid-cols-2 gap-[14px]">
                            <input v-model="doc.supplier_prefix" class="doc-input" />
                            <input v-model="doc.supplier_number" class="doc-input" placeholder="Consecutivo" />
                        </div>
                        <span v-else class="text-[11.5px]">{{ doc.related_label.split(' - ')[0] }}</span>
                    </div>
                    <div v-if="f.cost_center" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Centro de costo</span>
                        <select v-model="doc.cost_center_id" class="doc-input"><option :value="null">Buscar</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option></select>
                    </div>
                    <div v-if="f.seller_left" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Fecha de elaboración</span>
                        <div>
                            <input v-model="doc.date" type="date" class="doc-input req w-[150px]" :class="{ 'is-invalid': doc.errors.date }" />
                            <p v-if="doc.errors.date" class="text-[10px] text-danger">{{ doc.errors.date }}</p>
                        </div>
                    </div>
                    <div v-if="f.seller && !f.related && !f.seller_left" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">{{ f.seller_label ?? 'Vendedor' }} <Icon icon="circle-info" class="text-[9px] text-primary" /></span>
                        <DocSearch v-model="doc.seller_id" v-model:label="sellerLabel" :options="sellerOptions" />
                    </div>
                    <div v-if="f.reason && !f.seller_left" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Motivo de remisión</span>
                        <select v-model="doc.reason" class="doc-input"><option value=""></option><option v-for="r in f.reason" :key="r">{{ r }}</option></select>
                    </div>
                    <div v-if="f.dian_reason" class="grid grid-cols-[76px_1fr] items-start gap-[8px]">
                        <span class="doc-label">Motivo DIAN</span>
                        <div>
                            <select v-model="doc.reason" class="doc-input req" :class="{ 'is-invalid': doc.errors.reason }">
                                <option value=""></option>
                                <option v-for="(label, code) in f.dian_reason" :key="code" :value="code">{{ label }}</option>
                            </select>
                            <p v-if="doc.errors.reason" class="text-[10px] text-danger">{{ doc.errors.reason }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="doc.mandate" class="mt-[18px] grid grid-cols-2 gap-x-[24px] gap-y-[8px] rounded-[2px] border border-[#d7e3ea] bg-[#f7fbfd] p-[12px] text-[11.5px]">
                <p class="col-span-2 text-[12px] font-semibold">Mandante</p>
                <DocSearch v-model="doc.principal.contact_id" v-model:label="doc.principal.label" url="/api/buscar/terceros" :columns="CONTACT_COLUMNS" placeholder="Elegir un tercero" @pick="pickPrincipal" />
                <input v-model="doc.principal.identificacion" class="doc-input" placeholder="NIT" />
                <input v-model="doc.principal.nombre" class="doc-input" placeholder="Nombre" />
                <input v-model="doc.principal.ciudad" class="doc-input" placeholder="Ciudad" />
                <input v-model="doc.principal.regimen" class="doc-input" placeholder="Régimen" />
                <input v-model="doc.principal.correo" class="doc-input" placeholder="Correo" />
                <input v-model="doc.principal.direccion" class="doc-input col-span-2" placeholder="Dirección" />
                <input v-model="doc.principal.logo_url" class="doc-input" placeholder="Logo (URL, solo PDF)" />
                <input v-model="doc.principal.telefono" class="doc-input" placeholder="Teléfono (solo PDF)" />
                <input v-model="doc.principal.whatsapp" class="doc-input" placeholder="WhatsApp (solo PDF)" />
                <input v-model="doc.principal.sitio" class="doc-input" placeholder="Sitio (solo PDF)" />
                <textarea v-model="doc.principal.atencion" rows="2" class="doc-input col-span-2 h-auto py-[4px]" placeholder="Atención al cliente (solo PDF)" />
                <textarea v-model="doc.principal.cuentas" rows="2" class="doc-input col-span-2 h-auto py-[4px]" placeholder="Cuentas, una por línea: Banco; tipo; número" />
            </div>

            <div v-if="f.header_text" class="mt-[18px]">
                <p class="text-[15px] font-bold text-ink">{{ f.header_text }}</p>
                <textarea v-model="doc.header_text" rows="3" class="doc-input mt-[4px] h-auto py-[4px]" />
            </div>

            <!-- Items -->
            <div class="mt-[24px] overflow-x-auto">
                <table class="doc-grid w-full min-w-[700px]">
                    <thead>
                        <tr>
                            <th class="w-[14px]">#</th>
                            <th v-if="doc.mandate" class="w-[78px]">De</th>
                            <th v-if="f.item_type" :class="f.classic ? 'w-[78px]' : 'w-[62px]'">Tipo</th>
                            <th :class="f.classic ? '' : 'w-[120px]'">{{ f.classic ? 'Ítem' : 'Producto' }}</th>
                            <th :class="{ 'w-[90px]': f.classic }">Descripción</th>
                            <th class="w-[56px] text-right">{{ f.classic ? 'Cant.' : 'Cant' }}</th>
                            <th class="w-[86px] text-right">{{ f.classic ? 'Vr.Unitario' : 'Valor Unitario' }}</th>
                            <th class="w-[60px] text-right">{{ f.purchase ? 'Descuento' : '% Desc.' }}</th>
                            <template v-if="f.item_taxes">
                                <th class="w-[60px] text-center">{{ f.classic ? 'Imp Cargo' : 'Impuesto Cargo' }}</th>
                                <th v-if="f.classic" class="w-[70px] text-right">Vr.+Cargos</th>
                                <th class="w-[60px] text-center">{{ f.classic ? 'Imp Retención' : 'Impuesto Retención' }}</th>
                            </template>
                            <th v-else class="w-[120px] text-center">Impuestos</th>
                            <th class="w-[90px] text-right">{{ f.classic ? 'Valor' : 'Valor Total' }}</th>
                            <th class="w-[20px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(row, idx) in doc.items" :key="idx">
                            <!-- Linea terminada: se muestra como texto hasta que se vuelve a hacer clic. -->
                            <tr v-if="sale && (row.item_id || row.free) && editing !== idx" class="cursor-pointer text-[11.5px]" :class="shortOf(row) ? 'bg-[#fdecec] hover:bg-[#fbdcdc]' : 'hover:bg-[#fafafa]'" @click="editing = idx">
                                <td class="py-[6px] text-[11px]">{{ idx + 1 }}</td>
                                <td v-if="doc.mandate">{{ row.role === 'mandante' ? 'ISP' : 'Empresa' }}</td>
                                <td v-if="f.item_type">{{ row.type }}</td>
                                <td>{{ row.free ? 'Línea libre' : row.label }}</td>
                                <td>{{ row.description }}</td>
                                <td class="text-right" :class="shortOf(row) && 'font-bold text-[#b26a00]'" :title="shortOf(row) ? `Producto sin saldo. Disponible: ${money(row.stock)}` : null">
                                    {{ money(row.quantity) }}
                                    <div v-if="shortOf(row)" class="whitespace-nowrap text-[10px] font-normal">Sin saldo (disp. {{ money(row.stock) }})</div>
                                </td>
                                <td class="text-right">{{ money(taxIncluded ? Number(row.price) / (1 + rateOf(lineCalc(row).charge)) : row.price) }}</td>
                                <td class="text-right">{{ money(row.discount) }}</td>
                                <td>{{ [taxLabel(row), taxById(row.withholding_id, withholdings)?.name].filter((x) => x && x !== '0').join(', ') }}</td>
                                <td class="text-right">{{ money(lineCalc(row).base + lineCalc(row).tax) }}</td>
                                <td class="text-center"><button type="button" class="text-[12px] text-[#e57373]" title="Eliminar" @click.stop="removeItem(idx)"><Icon icon="trash-can" /></button></td>
                            </tr>
                            <tr v-else data-edit-row @focusin="editing = idx">
                                <td class="pt-[6px] text-[11px]">{{ idx + 1 }}</td>
                                <td v-if="doc.mandate">
                                    <select v-model="row.role" class="doc-input px-[2px]">
                                        <option value="mandante">ISP</option>
                                        <option value="mandatario">Empresa</option>
                                    </select>
                                </td>
                                <td v-if="f.item_type">
                                    <select v-model="row.type" class="doc-input px-[2px]" @change="row.item_id = null; row.label = ''">
                                        <option v-for="(label, t) in itemTypes" :key="t" :value="t">{{ label }}</option>
                                    </select>
                                </td>
                                <td>
                                    <span v-if="row.free" class="text-[11px] text-[#666]">Línea libre</span>
                                    <DocSearch v-else v-model="row.item_id" v-model:label="row.label" :url="ITEM_URL[row.type]" :placeholder="sale && idx > 0 && !row.item_id ? 'Agregar otro ítem' : 'Buscar'"
                                        :columns="sale && row.type === 'Producto' ? PRODUCT_COLUMNS : null" :create-url="sale && row.type === 'Producto' ? '/productos/crear' : ''"
                                        :display="sale && row.type === 'Producto' ? 'code' : 'label'" @pick="(i) => pickItem(row, idx, i)" />
                                </td>
                                <td><textarea v-model="row.description" rows="1" class="doc-input h-[25px] resize-y py-[3px]" :readonly="!f.purchase && !row.item_id && !row.free" /></td>
                                <td>
                                    <NumInput v-model="row.quantity" :class="{ 'is-invalid': shortOf(row) }" :title="shortOf(row) ? `Producto sin saldo. Disponible: ${money(row.stock)}` : null" />
                                    <p v-if="shortOf(row)" class="whitespace-nowrap text-[10px] text-[#b26a00]">Sin saldo (disp. {{ money(row.stock) }})</p>
                                </td>
                                <td>
                                    <div class="relative flex">
                                        <NumInput v-model="row.price" :class="{ req: f.purchase }" @update:model-value="row.list_price = null" @change="typedPrice(row)" />
                                        <button v-if="sale && priceOptions(row).length > 1" type="button" class="w-[14px] border border-l-0 border-[#ccc] text-[8px]" title="Listas de precios" @click="priceMenu = priceMenu === idx ? null : idx"><Icon icon="chevron-down" /></button>
                                        <ul v-if="priceMenu === idx" class="absolute right-0 top-[26px] z-30 min-w-[180px] border border-[#ccc] bg-white text-[11px] shadow-lg">
                                            <li v-for="o in priceOptions(row)" :key="o.name"><button type="button" class="flex w-full justify-between gap-[10px] px-[8px] py-[3px] hover:bg-[#f2f2f2]" @click="pickPrice(row, o.value)"><span>{{ o.name }}</span><span>{{ money(o.value) }}</span></button></li>
                                        </ul>
                                    </div>
                                </td>
                                <td><NumInput v-model="row.discount" /></td>
                                <template v-if="f.item_taxes">
                                    <td>
                                        <select v-model="row.tax_id" class="doc-input px-[1px]" :disabled="row.tax_code === 'ZZ'" @change="retax(row, row.tax_id)"><option :value="null"></option><option v-for="t in itemCharges" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                                        <label v-if="mandateDoc" class="mt-[2px] flex items-center gap-[2px] text-[10px]"><input type="checkbox" :checked="row.tax_code === 'ZZ'" @change="setZz(row, $event.target.checked)" /> No aplica (ZZ)</label>
                                    </td>
                                    <td v-if="f.classic"><input :value="money(lineCalc(row).base + lineCalc(row).tax)" readonly class="doc-input doc-num bg-[#f2f2f2]" /></td>
                                    <td><select v-model="row.withholding_id" class="doc-input px-[1px]"><option :value="null"></option><option v-for="t in itemWithholdings" :key="t.id" :value="t.id">{{ t.name }}</option></select></td>
                                </template>
                                <td v-else>
                                    <div class="flex items-center gap-[4px]">
                                        <input :value="taxLabel(row)" readonly class="doc-input bg-[#f2f2f2]" />
                                        <button type="button" class="flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-full border border-[#555] text-[10px] text-[#555]" title="Impuestos" @click="openTaxes(row)"><Icon icon="plus" /></button>
                                        <label v-if="mandateDoc" class="flex items-center gap-[2px] text-[10px]"><input type="checkbox" :checked="row.tax_code === 'ZZ'" @change="setZz(row, $event.target.checked)" /> ZZ</label>
                                    </div>
                                </td>
                                <td><input :value="row.item_id || row.free || !sale ? money(sale ? lineCalc(row).base + lineCalc(row).tax : lineCalc(row).base) : ''" readonly class="doc-input doc-num" /></td>
                                <td v-if="f.classic" class="pt-[3px] text-center"><button v-if="idx === doc.items.length - 1" type="button" class="h-[18px] w-[18px] border border-[#999] bg-[#f4f4f4] text-[9px]" title="Agregar línea" @click="doc.items.push(blankItem())"><Icon icon="plus" /></button>
                                    <button v-else type="button" class="text-[12px] text-[#e57373]" title="Eliminar" @click="removeItem(idx)"><Icon icon="trash-can" /></button></td>
                                <td v-else class="pt-[5px] text-center"><button type="button" class="text-[12px] text-[#e57373]" title="Eliminar" @click="removeItem(idx)"><Icon icon="trash-can" /></button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div v-if="shortages.length" class="mt-[6px] flex gap-[8px] rounded-[2px] border border-[#f0c36d] bg-[#fff4de] px-[10px] py-[6px] text-[11px] text-[#7a4f00]" role="alert">
                    <Icon icon="triangle-exclamation" class="mt-[2px]" />
                    <div><b>Producto sin saldo suficiente.</b> Puedes guardar igual; el inventario quedará en negativo:
                        <div v-for="m in shortages" :key="m">{{ m }}</div>
                    </div>
                </div>
                <button v-if="mandateDoc" type="button" class="doc-link mt-[6px]" @click="addFreeLine()">+ Línea libre</button>
                <p v-if="doc.errors.items" class="mt-[4px] text-[11px] text-danger">{{ doc.errors.items }}</p>
            </div>

            <!-- Formas de pago y totales -->
            <div class="mt-[30px] grid grid-cols-[minmax(0,1fr)_230px] gap-[30px] border-t border-[#e5e5e5] pt-[12px]">
                <div class="min-w-0">
                    <template v-if="f.payments">
                        <p class="doc-section border-b border-[#ccc] pb-[10px]">Formas de pago</p>
                        <div v-if="f.doc === 'FV'" class="flex items-center gap-[14px] border-b border-[#ccc] py-[6px] text-[11.5px]">
                            <label class="flex items-center gap-[4px]"><input type="radio" :checked="doc.payment_form !== 'contado'" @change="doc.payment_form = ''" /> Formas de pago</label>
                            <label class="flex items-center gap-[4px]"><input type="radio" :checked="doc.payment_form === 'contado'" @change="doc.payment_form = 'contado'; doc.credit = false" /> Contado, ya pagado</label>
                        </div>
                        <div v-if="f.credit_toggle" class="flex items-center gap-[14px] border-b border-[#ccc] py-[6px] text-[11.5px]">
                            Método de pago:
                            <label class="flex items-center gap-[4px]"><input v-model="doc.credit" type="radio" :value="false" /> Contado</label>
                            <label class="flex items-center gap-[4px]"><input v-model="doc.credit" type="radio" :value="true" @change="doc.due_date ||= addDays(doc.date, 30)" /> Crédito</label>
                            <label v-if="doc.credit" class="ml-auto flex items-center gap-[6px]">Vence <input v-model="doc.due_date" type="date" class="doc-input w-[130px]" /></label>
                        </div>
                        <div v-for="(p, idx) in (doc.payment_form === 'contado' || (f.credit_toggle && doc.credit) ? [] : doc.payments)" :key="idx" class="flex items-end justify-between gap-[10px] py-[8px]">
                            <div class="flex items-end gap-[10px]">
                                <DocSearch v-model="p.method_id" v-model:label="p.label" :options="paymentMethods" :columns="PAYMENT_COLUMNS" create-url="/configuracion/formas-pago/crear"
                                    placeholder="Selecciona forma de pago" class="w-[140px]" @pick="(m) => pickPayment(p, m)" />
                                <template v-if="methodById(p.method_id)?.credit">
                                    <select v-model="p.range" class="doc-input w-[52px] px-[2px]" @change="p.due_date = addDays(doc.date, p.range)">
                                        <option v-for="[v, l] in RANGES" :key="v" :value="v">{{ l }}</option>
                                    </select>
                                    <label class="flex flex-col text-[9.5px] font-semibold text-[#555]">
                                        <span>Fecha de pago<span class="text-danger">*</span></span>
                                        <input v-model="p.due_date" type="date" class="doc-input w-[118px]" />
                                    </label>
                                </template>
                            </div>
                            <div class="flex items-center gap-[30px]">
                                <NumInput v-model="p.value" class="w-[78px]" />
                                <button type="button" class="text-[12px] text-[#e57373]" @click="doc.payments.length > 1 ? doc.payments.splice(idx, 1) : (doc.payments[0] = blankPayment())"><Icon icon="trash-can" /></button>
                            </div>
                        </div>
                        <button v-if="doc.payment_form !== 'contado' && !(f.credit_toggle && doc.credit)" type="button" class="doc-link border-t border-[#ccc] pt-[6px]" @click="doc.payments.push(blankPayment())">+Agregar otra forma de pago</button>
                    </template>
                </div>
                <dl class="space-y-[9px] text-[12px]">
                    <div class="flex justify-between"><dt class="w-[150px] text-right">{{ f.classic ? 'Total bruto' : 'Total Bruto:' }}</dt><dd>{{ money(totals.gross) }}</dd></div>
                    <div class="flex justify-between"><dt class="w-[150px] text-right">Descuentos{{ f.classic ? '' : ':' }}</dt><dd>{{ money(totals.disc) }}</dd></div>
                    <div class="flex justify-between"><dt class="w-[150px] text-right">Subtotal{{ f.classic ? '' : ':' }}</dt><dd>{{ money(totals.subtotal) }}</dd></div>
                    <div v-for="c in totals.charges" :key="'c' + c.name" class="flex justify-between"><dt class="w-[150px] text-right">{{ c.name }}:</dt><dd>{{ money(c.value) }}</dd></div>
                    <div v-for="w in totals.withholdings" :key="'w' + w.name" class="flex justify-between"><dt class="w-[150px] text-right">{{ w.name }}:</dt><dd>-{{ money(w.value) }}</dd></div>
                    <div v-for="r in totals.summary" :key="r.kind" class="flex items-center justify-between">
                        <dt class="flex items-center gap-[4px]">{{ r.kind }}{{ f.classic ? '' : ':' }}
                            <select v-model="doc.summary_retentions[r.kind]" class="doc-input w-[126px]"><option :value="null"></option><option v-for="t in summaryOptions(r.kind)" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                        </dt>
                        <dd>{{ r.value ? '-' : '' }}{{ money(r.value) }}</dd>
                    </div>
                    <div v-if="f.net && !f.payments" class="flex justify-between border-t border-[#ccc] pt-[6px] text-[14px] font-bold"><dt class="w-[150px] text-right">{{ f.classic ? 'Total neto' : 'Total Neto:' }}</dt><dd>{{ money(totals.net) }}</dd></div>
                </dl>
            </div>

            <div v-if="f.payments" class="mt-[24px] grid grid-cols-2 gap-[40px] text-[17px] font-bold text-[#333]">
                <div>
                    <p class="flex items-center justify-end gap-[16px]">Total formas de pagos: <span>{{ money(totals.paid) }}</span>
                        <Icon :icon="totals.paid === totals.net ? 'circle-check' : 'circle-xmark'" :class="totals.paid === totals.net ? 'text-ok' : 'text-danger'" /></p>
                    <p v-if="totals.paid !== totals.net && totals.net > 0" class="mt-[4px] text-right text-[10px] font-semibold text-danger">El total de las formas de pago debe coincidir con el total neto ({{ money(totals.net) }})</p>
                </div>
                <p class="flex items-start justify-end gap-[24px]">Total Neto: <span>{{ money(totals.net) }}</span></p>
            </div>

            <div v-if="f.footer_text" class="mt-[18px]">
                <p class="text-[15px] font-bold text-ink">{{ f.footer_text }}</p>
                <textarea v-model="doc.footer_text" rows="3" class="doc-input mt-[4px] h-auto py-[4px]" />
            </div>

            <div v-if="f.observations" class="mt-[24px] max-w-[400px]">
                <p v-if="!f.classic" class="doc-section">Observaciones</p>
                <textarea v-model="doc.observations" rows="3" class="doc-input mt-[4px] h-auto py-[4px]"
                    :placeholder="sale ? 'Aquí puedes ingresar comentarios adicionales o información para tu cliente. Por ejemplo: “Favor consignar a la cuenta No. 000000 del banco XYZ”' : 'Observaciones'" />
                <label class="mt-[6px] inline-flex cursor-pointer items-center gap-[8px] px-[8px] text-[11px]">
                    Adjuntar archivo <Icon icon="paperclip" />
                    <input type="file" class="hidden" @change="attachment = $event.target.files[0] ?? null" />
                    <span v-if="attachment" class="text-muted">{{ attachment?.name }}</span>
                </label>
            </div>
        </div>

        <div v-if="f.classic" class="mt-[40px] flex justify-end gap-[8px] px-[14px] pb-[20px]">
            <button v-for="b in f.buttons" :key="b" type="button" class="doc-btn doc-btn-green" :disabled="doc.processing" @click="submit(b)">{{ b }}</button>
            <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex items-center">Cancelar</Link>
        </div>
        <div v-else class="doc-footer">
            <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex items-center">Cancelar</Link>
            <button v-for="b in f.buttons" :key="b" type="button" class="doc-btn doc-btn-green" :disabled="doc.processing" @click="submit(b)">{{ b }}</button>
        </div>
    </form>

    <!-- Impuestos de la linea -->
    <div v-if="taxModal" class="fixed inset-0 z-50 flex items-start justify-center p-3 bg-black/40 pt-[60px] md:pt-[210px]" @click.self="taxModal = null">
        <div class="w-[350px] max-w-full max-h-[calc(100vh-24px)] overflow-y-auto bg-white px-[14px] pb-[14px] pt-[10px] text-[11.5px] text-[#333] shadow-xl">
            <div class="flex justify-end"><button type="button" class="text-[12px] text-danger" @click="taxModal = null"><Icon icon="xmark" /></button></div>
            <p class="mt-[16px] font-semibold">Valor subtotal</p>
            <p class="mt-[2px] bg-[#e6e6e6] px-[4px] py-[1px] text-right font-semibold">{{ money(taxModal.base) }}</p>
            <p class="mt-[10px] font-semibold">Impuesto cargo</p>
            <select v-model="taxModal.tax_id" class="doc-input mt-[2px]"><option :value="null">Ninguno</option><option v-for="t in itemCharges" :key="t.id" :value="t.id">{{ t.name }}</option></select>
            <p class="mt-[10px] font-semibold">Impuesto retención</p>
            <select v-model="taxModal.withholding_id" class="doc-input mt-[2px]"><option :value="null">Ninguno</option><option v-for="t in saleWithholdings" :key="t.id" :value="t.id">{{ t.name }}</option></select>
            <div class="mt-[8px] flex">
                <button type="button" class="bg-[#0275b3] px-[10px] py-[5px] text-[12px] text-white" @click="taxModal = null">Cancelar</button>
                <button type="button" class="bg-[#7cb342] px-[10px] py-[5px] text-[12px] text-white" @click="saveTaxes">Guardar</button>
            </div>
        </div>
    </div>
</template>
