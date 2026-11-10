<script setup>
import { printInPlace } from '../../composables/print';
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '../../composables/dialog';
import PurchaseSheet from '../../Components/PurchaseSheet.vue';

/* Detalle de compras: titulo y acciones, representacion grafica y debajo informacion general, estado de cuenta y anexos. */
const props = defineProps({ doc: { type: Object, required: true }, company: Object });
const d = props.doc;
const TITLES = { FC: 'Factura de compra / gasto', DS: 'Documento soporte', ND: 'Nota débito', NA: 'Nota de ajuste documento soporte', OC: 'Orden de compra', SI: 'Saldo inicial' };
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const annulled = d.status === 'annulled';
const payable = !annulled && ['FC', 'DS', 'SI'].includes(d.type) && Number(d.totals.balance) > 0;
const menu = ref(false);
const showLedger = ref(false);
const fileInput = ref(null);
const page = usePage();
const error = computed(() => page.props.errors?.items);
const ledgerTotal = (k) => d.ledger.reduce((a, l) => a + l[k], 0);

async function annul() {
    menu.value = false;
    const dian = d.type === 'DS' && d.dian?.status === 'Accepted' ? ' La DIAN ya lo aceptó: el documento electrónico sigue vigente ante la DIAN.' : '';
    if (await confirmDialog(`¿Anular el documento ${d.name}? Se reversará su contabilización, inventario y saldos.${dian}`)) router.post(`/documentos/${d.kind}/${d.id}/anular`, {}, { preserveScroll: true, preserveState: false });
}
function upload(e) {
    const file = e.target.files?.[0];
    if (file) router.post(`/documentos/${d.kind}/${d.id}/adjuntos`, { attachment: file }, { forceFormData: true, preserveScroll: true, preserveState: false });
}
</script>

<template>
    <Head :title="d.name" />
    <div class="pb-[30px]" @click="menu = false">
        <h1 class="text-[20px] font-bold text-ink">{{ TITLES[d.type] ?? d.type_name }}: {{ d.name }}</h1>
        <p class="mt-[10px] text-[12px] text-ink-soft">Creado: {{ [d.created?.by, d.created?.at].filter(Boolean).join(' ') }} hrs.</p>

        <div class="mt-[12px] flex items-center gap-[10px] border-t border-line pt-[14px]" @click.stop>
            <Link v-if="payable" :href="`/compras/egresos/crear?factura=${d.id}`" class="s-btn s-btn-primary h-[30px] px-[12px] text-[12px] font-semibold"><Icon icon="dollar-sign" class="mr-[6px]" />Registrar pago</Link>
            <button type="button" class="s-btn s-btn-outline h-[30px] px-[12px] text-[12px] font-semibold" @click="printInPlace(`/documentos/${d.kind}/${d.id}/imprimir`)"><Icon icon="print" class="mr-[6px]" />Descargar e imprimir</button>
            <div class="relative">
                <button type="button" class="s-btn s-btn-outline h-[30px] px-[12px] text-[12px] font-semibold" @click="menu = !menu">Más <Icon icon="caret-down" class="ml-[6px]" /></button>
                <div v-if="menu" class="absolute left-0 top-[34px] z-20 min-w-[210px] rounded-[4px] border border-line bg-white py-[4px] text-[13px] shadow-lg">
                    <Link v-if="d.editable && !d.blocker && !annulled" :href="`/documentos/${d.kind}/${d.id}/editar`" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">Editar</Link>
                    <Link v-if="d.copy_url" :href="d.copy_url" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">Copiar</Link>
                    <button v-if="!d.blocker && !annulled" type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f9ff]" @click="annul">Anular</button>
                    <button type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f9ff]" @click="showLedger = !showLedger; menu = false">{{ showLedger ? 'Ocultar contabilización' : 'Ver contabilización' }}</button>
                    <a v-if="d.dian?.xml" :href="`/documentos/${d.kind}/${d.id}/xml`" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">Descargar XML</a>
                    <a v-if="d.dian?.response" :href="`/documentos/${d.kind}/${d.id}/xml?tipo=respuesta`" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">Respuesta DIAN</a>
                </div>
            </div>
        </div>

        <div v-if="annulled" class="mt-[12px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">Documento anulado<span v-if="d.annulled_at"> el {{ d.annulled_at }}</span>.</div>
        <div v-else-if="d.blocker && d.source === 'erp'" class="mt-[12px] rounded-[2px] border border-[#ffcc80] bg-[#fff8e1] px-4 py-2 text-[12px] text-[#8d6e00]">{{ d.blocker }}</div>
        <div v-if="d.dian && d.dian.status !== 'Accepted'" class="mt-[12px] rounded-[2px] border border-[#ffcc80] bg-[#fff8e1] px-4 py-2 text-[12px] text-[#8d6e00]">{{ d.dian.message || (d.type === 'NA' ? 'La nota de ajuste está pendiente de aprobación de la DIAN.' : 'El documento soporte está pendiente de aprobación de la DIAN.') }}</div>
        <div v-if="error" class="mt-[12px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">{{ error }}</div>

        <div class="mt-[14px] rounded-[4px] bg-white p-[10px]">
            <div class="border border-[#e0e0e0]"><PurchaseSheet :doc="d" :company="company" /></div>
        </div>

        <section v-if="showLedger" class="s-card mt-[14px]">
            <h2 class="px-4 pt-3 text-[13px] font-bold">Contabilización</h2>
            <p v-if="!d.ledger.length" class="p-4 text-[12px] text-muted">{{ annulled ? 'El documento anulado no tiene contabilización.' : 'Este documento no genera contabilización.' }}</p>
            <table v-else class="s-table">
                <thead><tr><th>Cuenta</th><th>Tercero</th><th>Descripción</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr></thead>
                <tbody>
                    <tr v-for="(l, k) in d.ledger" :key="k"><td>{{ l.account }}</td><td>{{ l.contact }}</td><td>{{ l.description }}</td><td class="text-right">{{ l.debit ? money(l.debit) : '' }}</td><td class="text-right">{{ l.credit ? money(l.credit) : '' }}</td></tr>
                    <tr class="font-bold"><td colspan="3" class="text-right">Totales</td><td class="text-right">{{ money(ledgerTotal('debit')) }}</td><td class="text-right">{{ money(ledgerTotal('credit')) }}</td></tr>
                </tbody>
            </table>
        </section>

        <div class="mt-[18px] grid grid-cols-[1fr_1fr_0.8fr] gap-[14px] text-[11.5px] text-ink">
            <section class="min-h-[160px] rounded-[4px] bg-white p-[16px]">
                <h2 class="text-[14px] font-bold">Información General</h2>
                <dl class="mt-[18px] grid grid-cols-[130px_1fr] gap-y-[4px]">
                    <dt>Proveedor</dt>
                    <dd><Link v-if="d.contact_id" :href="`/terceros/${d.contact_id}`" class="text-primary hover:underline">{{ d.contact?.name }}</Link></dd>
                    <dt>Deuda total proveedor</dt>
                    <dd><Link v-if="d.contact_id" :href="`/compras/proveedores/${d.contact_id}`" class="text-primary hover:underline">$ {{ money(d.debt) }}</Link></dd>
                    <dt>Comprobantes relacionados</dt>
                    <dd>
                        <Link v-if="d.related_url" :href="d.related_url" class="text-primary hover:underline">{{ d.related }}</Link>
                        <span v-else-if="d.related">{{ d.related }}</span>
                        <Link v-for="n in d.adjustments || []" :key="n.url" :href="n.url" class="block text-primary hover:underline">{{ n.name }}</Link>
                        <span v-if="!d.related && !(d.adjustments || []).length">—</span>
                    </dd>
                </dl>
            </section>
            <section class="min-h-[160px] rounded-[4px] bg-white p-[16px]">
                <h2 class="text-[14px] font-bold">Estado de cuenta de esta factura</h2>
                <dl class="mt-[18px] grid grid-cols-[1fr_20px_auto] gap-y-[2px]">
                    <template v-for="[label, key] in [['Total compra', 'total'], ['Saldo proveedores', 'balance'], ['Valor vencido', 'overdue'], ['Valor por vencer', 'pending']]" :key="key">
                        <dt>{{ label }}</dt><dd>$</dd><dd class="text-right">{{ money(d.statement?.[key]) }}</dd>
                    </template>
                </dl>
                <Link v-if="d.contact_id" :href="`/compras/proveedores/${d.contact_id}`" class="mt-[4px] block pl-[50%] text-primary hover:underline">Ver detalle</Link>
            </section>
            <section class="min-h-[160px] rounded-[4px] bg-white p-[16px]">
                <h2 class="text-[14px] font-bold">Anexos</h2>
                <a v-for="a in d.attachments" :key="a.id" :href="a.url" class="mt-[10px] block text-primary hover:underline"><Icon icon="paperclip" /> {{ a.name }}</a>
                <button type="button" class="mt-[22px] px-[16px] text-ink" @click="fileInput.click()">Adjuntar archivo <Icon icon="paperclip" class="ml-[6px]" /></button>
                <input ref="fileInput" type="file" class="hidden" @change="upload" />
            </section>
        </div>
    </div>
</template>
