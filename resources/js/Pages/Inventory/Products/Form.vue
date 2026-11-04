<script setup>
import NumInput from '../../../Components/Forms/NumInput.vue';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Lookup from '../../../Components/Lookup.vue';

const props = defineProps({
    product: { type: Object, default: null },
    type: { type: String, required: true },
    groups: { type: Array, required: true },
    lines: { type: Array, required: true },
    units: { type: Array, required: true },
    chargeTaxes: { type: Array, required: true },
    withholdingTaxes: { type: Array, required: true },
    priceLists: { type: Array, required: true },
    copy: { type: Object, default: null },
    ivaResponsible: { type: Boolean, default: true },
});

const p = props.product ?? props.copy ?? {};
const taxIds = (p.tax_ids ?? []).map(Number);
const charge = props.chargeTaxes.find((t) => taxIds.includes(t.id));
const withholding = props.withholdingTaxes.find((t) => taxIds.includes(t.id));
const defaultIva = props.ivaResponsible ? props.chargeTaxes.find((t) => t.type === 'IVA' && Number(t.percentage) === 19) : null;

const form = useForm({
    type: p.type ?? props.type,
    account_group_id: p.account_group_id ?? null,
    product_line_id: p.product_line_id ?? null,
    code: p.code ?? '',
    name: p.name ?? '',
    barcode: p.barcode ?? '',
    unit_code: p.unit_code ?? '94',
    stock_control: p.stock_control ?? true,
    visible_in_sales: p.visible_in_sales ?? true,
    tax_classification: p.tax_classification ?? 'Taxed',
    tax_included: p.tax_included ?? true,
    reference: p.reference ?? '',
    description: p.description ?? '',
    brand: p.brand ?? '',
    model: p.model ?? '',
    tariff: p.tariff ?? '',
    min_stock: Number(p.min_stock ?? 0),
    prices: Object.fromEntries(props.priceLists.map((l) => [l.id, p.prices?.[l.id] != null ? Number(p.prices[l.id]) : ''])),
    active: p.active ?? true,
    tax_ids: [],
    components: p.components?.length ? p.components : [{ product_id: null, product_id_label: '', quantity: 1 }],
});
const isCombo = computed(() => form.type === 'Combo');
const back = computed(() => (isCombo.value ? '/productos/combos' : '/productos'));
const chargeTax = ref(charge?.id ?? (props.product ? null : defaultIva?.id ?? null));
const withholdingTax = ref(withholding?.id ?? null);
const tab = ref('impuestos');
const codeHint = ref('');
const unitFilter = ref('');
const filteredUnits = computed(() => {
    const q = unitFilter.value.toLowerCase();
    const list = q ? props.units.filter((u) => u.label.toLowerCase().includes(q)) : props.units.slice(0, 60);
    const current = props.units.find((u) => u.code === form.unit_code);
    return current && !list.includes(current) ? [current, ...list] : list;
});

async function nextCode() {
    if (!form.product_line_id) return;
    const res = await fetch(`/productos/siguiente-codigo/${form.product_line_id}`);
    const data = await res.json();
    form.code = data.code;
    codeHint.value = data.last ? `Último código de la línea: ${data.last}. Siguiente consecutivo: ${data.sequence}` : 'La línea no tiene productos.';
}
function onLine() {
    if (!props.product && form.product_line_id) nextCode();
}
function submit() {
    form.transform((d) => ({ ...d, tax_ids: [chargeTax.value, withholdingTax.value].filter(Boolean) }));
    if (props.product) form.put(`/productos/${props.product.id}`, { preserveScroll: true });
    else form.post('/productos');
}
const isProduct = computed(() => form.type === 'Product');
</script>

<template>
    <Head :title="product ? 'Editar producto' : 'Creación de producto'" />
    <div class="-mx-3 md:-mx-[22px] flex h-[56px] items-center gap-4 bg-primary px-[22px] text-white">
        <h1 class="text-[16px] font-bold">{{ isCombo ? (product ? 'Edición de combo' : 'Creación de combo') : product ? 'Edición de producto' : 'Creación de producto' }}</h1>
        <span v-if="product" class="text-[13px] opacity-90">{{ product.code }} · {{ product.name }}</span>
        <Link :href="back" class="ml-auto text-[18px]" title="Cerrar"><Icon icon="xmark" /></Link>
    </div>

    <form class="mt-[16px] flex flex-col gap-[16px] pb-[70px]" @submit.prevent="submit">
        <section v-if="!isCombo" class="s-card flex gap-6 px-[16px] py-[18px]">
            <label class="flex items-center gap-2"><input v-model="form.type" type="radio" value="Product" class="accent-primary" /> Producto</label>
            <label class="flex items-center gap-2"><input v-model="form.type" type="radio" value="Service" class="accent-primary" /> Servicio</label>
        </section>

        <section v-if="isCombo || form.type === 'Product'" class="s-card px-[16px] py-[18px]">
            <h2 class="s-section-title">{{ isCombo ? 'Productos que componen el combo' : 'Ensamble: materia prima o producto en proceso' }}</h2>
            <p class="mt-1 text-[12px] text-muted">{{ isCombo ? 'Al vender el combo se descuenta del inventario la cantidad de cada producto por cada unidad vendida.' : 'Opcional. Cantidad requerida por unidad; se carga al crear una nota de ensamble de este producto.' }}</p>
            <div class="s-table-wrap">
                <table class="s-table mt-3 w-full">
                    <thead><tr><th>Producto</th><th class="w-[160px] text-right">Cantidad</th><th class="w-8" /></tr></thead>
                    <tbody>
                        <tr v-for="(c, i) in form.components" :key="i">
                            <td><Lookup v-model="c.product_id" v-model:label="c.product_id_label" url="/api/buscar/productos" placeholder="Buscar producto" /></td>
                            <td><input v-model="c.quantity" type="number" min="0" step="any" class="s-input text-right" /></td>
                            <td><button type="button" class="text-muted hover:text-[#c62828]" @click="form.components.splice(i, 1)"><Icon icon="trash-can" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="mt-2 text-[13px] font-semibold text-primary hover:underline" @click="form.components.push({ product_id: null, product_id_label: '', quantity: 1 })"><Icon icon="circle-plus" /> Agregar producto</button>
            <p v-if="Object.keys(form.errors).find((k) => k.startsWith('components'))" class="s-error">{{ form.errors[Object.keys(form.errors).find((k) => k.startsWith('components'))] }}</p>
        </section>

        <section class="s-card px-[16px] py-[18px]">
            <h2 class="s-section-title">Datos generales</h2>
            <div class="mt-6 grid grid-cols-3 gap-x-4 gap-y-5">
                <label>
                    <span class="s-label">Grupo de inventario<span class="req">*</span></span>
                    <select v-model="form.account_group_id" class="s-input" :class="{ 'is-invalid': form.errors.account_group_id }">
                        <option :value="null">Elige una opción</option>
                        <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                    </select>
                    <p v-if="form.errors.account_group_id" class="s-error">{{ form.errors.account_group_id }}</p>
                </label>
                <label>
                    <span class="s-label">Código de producto (SKU)<span class="req">*</span></span>
                    <div class="flex gap-2">
                        <input v-model="form.code" class="s-input" :class="{ 'is-invalid': form.errors.code }" />
                        <button type="button" class="s-btn s-btn-outline px-2" title="Siguiente código de la línea" :disabled="!form.product_line_id" @click="nextCode">
                            <Icon icon="arrow-left" class="rotate-180" />
                        </button>
                    </div>
                    <p v-if="form.errors.code" class="s-error">{{ form.errors.code }}</p>
                    <p v-else-if="codeHint" class="mt-1 text-[11px] text-muted">{{ codeHint }}</p>
                </label>
                <label>
                    <span class="s-label">Nombre del producto<span class="req">*</span></span>
                    <input v-model="form.name" class="s-input" :class="{ 'is-invalid': form.errors.name }" />
                    <p v-if="form.errors.name" class="s-error">{{ form.errors.name }}</p>
                </label>
                <label>
                    <span class="s-label">Línea (depósito)</span>
                    <select v-model="form.product_line_id" class="s-input" @change="onLine">
                        <option :value="null">Detectar por código</option>
                        <option v-for="l in lines" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }}</option>
                    </select>
                </label>
                <label>
                    <span class="s-label">Código de barras</span>
                    <input v-model="form.barcode" class="s-input" />
                </label>
                <label>
                    <span class="s-label">Unidad de medida DIAN<span class="req">*</span></span>
                    <input v-model="unitFilter" class="s-input mb-1 h-[28px]" placeholder="Filtrar unidades" />
                    <select v-model="form.unit_code" class="s-input">
                        <option v-for="u in filteredUnits" :key="u.code" :value="u.code">{{ u.label }}</option>
                    </select>
                </label>
            </div>
            <div class="mt-5 flex flex-col gap-3">
                <label v-if="isProduct" class="flex items-center gap-3"><span class="s-switch"><input v-model="form.stock_control" type="checkbox" /><span /></span> Producto inventariable</label>
                <label class="flex items-center gap-3"><span class="s-switch"><input v-model="form.visible_in_sales" type="checkbox" /><span /></span> Visible en facturas de venta</label>
                <label class="flex items-center gap-3"><span class="s-switch"><input v-model="form.active" type="checkbox" /><span /></span> Activo</label>
            </div>
        </section>

        <section class="s-card px-[16px] py-[18px]">
            <h2 class="s-section-title">Datos adicionales</h2>
            <div class="s-subtabs mt-5">
                <button type="button" class="s-subtab" :class="{ active: tab === 'impuestos' }" @click="tab = 'impuestos'">Impuestos</button>
                <button type="button" class="s-subtab" :class="{ active: tab === 'stock' }" @click="tab = 'stock'">Descripción y stock</button>
            </div>
            <div v-if="tab === 'impuestos'" class="mt-4">
                <p class="text-ink">Estos impuestos aplican solo para documentos de ventas</p>
                <div class="mt-4 grid grid-cols-3 gap-4">
                    <label><span class="s-label">Impuesto cargo</span>
                        <select v-model="chargeTax" class="s-input">
                            <option :value="null">No aplica</option>
                            <option v-for="t in chargeTaxes" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </label>
                    <label><span class="s-label">Retención</span>
                        <select v-model="withholdingTax" class="s-input">
                            <option :value="null">No aplica</option>
                            <option v-for="t in withholdingTaxes" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </label>
                    <label><span class="s-label">Clasificación tributaria</span>
                        <select v-model="form.tax_classification" class="s-input">
                            <option value="Taxed">Gravado</option>
                            <option value="Exempt">Exento</option>
                            <option value="Excluded">Excluido</option>
                        </select>
                    </label>
                </div>
            </div>
            <div v-else class="mt-4 grid grid-cols-3 gap-4">
                <label><span class="s-label">Referencia</span><input v-model="form.reference" class="s-input" /></label>
                <label><span class="s-label">Marca</span><input v-model="form.brand" class="s-input" /></label>
                <label><span class="s-label">Modelo</span><input v-model="form.model" class="s-input" /></label>
                <label><span class="s-label">Partida arancelaria</span><input v-model="form.tariff" class="s-input" /></label>
                <label v-if="isProduct"><span class="s-label">Stock mínimo</span><input v-model="form.min_stock" type="number" min="0" step="any" class="s-input" /></label>
                <label class="col-span-3"><span class="s-label">Descripción</span><textarea v-model="form.description" rows="3" class="s-input" /></label>
            </div>
        </section>

        <section class="s-card px-[16px] py-[18px]">
            <div class="flex items-center">
                <h2 class="s-section-title">Precios de venta</h2>
                <label class="ml-auto flex items-center gap-3"><span class="s-switch"><input v-model="form.tax_included" type="checkbox" /><span /></span> Precio con impuestos incluidos</label>
            </div>
            <div class="mt-4 grid grid-cols-4 gap-4">
                <label v-for="l in priceLists" :key="l.id"><span class="s-label">{{ l.name }}</span>
                    <NumInput v-model="form.prices[l.id]" plain class="s-input text-right" />
                </label>
            </div>
        </section>
    </form>

    <div class="fixed bottom-0 left-[92px] right-0 z-20 flex h-[52px] items-center gap-3 border-t border-line bg-white px-[24px]">
        <Link :href="back" class="text-primary">Cancelar</Link>
        <Link v-if="product && product.stock_control" :href="`/productos/${product.id}/kardex`" class="s-btn s-btn-outline ml-auto">Ver kardex</Link>
        <button type="button" class="s-btn s-btn-primary w-[110px]" :class="{ 'ml-auto': !(product && product.stock_control) }" :disabled="form.processing" @click="submit">Guardar</button>
    </div>
</template>
