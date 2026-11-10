<script setup>
/* Representacion grafica de compras: factura de compra / gasto, documento soporte, notas y ordenes de compra. */
const props = defineProps({ doc: Object, company: Object });
const d = props.doc;
const c = props.company ?? {};
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
const rate = (v) => `${Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })} %`;
const dots = (v) => String(v ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
const nit = (id, dv) => (id ? dots(id) + (dv !== null && dv !== undefined && dv !== '' ? `-${dv}` : '') : '');
const number = String(d.name ?? '').split('-').pop();
const dateLabel = { FC: 'Fecha de compra', OC: 'Fecha de orden' }[d.type] ?? 'Fecha';
const gross = Number(d.totals.subtotal) + Number(d.totals.discount);
const phones = [c.phone, c.phone2].filter(Boolean).filter((p, i, a) => a.indexOf(p) === i);
</script>

<template>
    <article class="psheet">
        <p v-if="d.source === 'siigo'" class="side">Elaborado por Siigo S.A.S 830.048.145-8</p>
        <header class="top">
            <div class="logo"><img v-if="c.logo" :src="c.logo" alt="" /></div>
            <div class="company">
                <b>{{ c.name }}</b>
                <p>Nit {{ nit(c.nit_number, c.dv) }}</p>
                <b v-if="c.trade_name">{{ c.trade_name }}</b>
                <p>{{ c.address }}</p>
                <p v-if="phones.length">Tel: ({{ phones[0] }}) {{ phones.slice(1).join(' ') || phones[0] }}</p>
                <p v-if="c.city">{{ c.city }} - Colombia</p>
            </div>
            <div class="box">
                <p>{{ d.type_name }}</p>
                <p><b>No. {{ number }}</b></p>
            </div>
        </header>
        <div v-if="d.status === 'annulled'" class="ribbon"><span>Anulada</span></div>

        <div class="heading">
            <table class="party">
                <tr><th>Proveedor</th><td colspan="3">{{ d.contact?.name }}</td></tr>
                <tr><th>Nit</th><td>{{ nit(d.contact?.identification, d.contact?.dv) }}</td><th>Teléfono</th><td>{{ d.contact_extra?.phone }}</td></tr>
                <tr><th>Dirección</th><td>{{ d.contact?.address }}</td><th>Ciudad</th><td>{{ d.contact_extra?.city }}</td></tr>
                <tr><th>Centro de costo</th><td colspan="3">{{ d.cost_center }}</td></tr>
                <tr v-if="d.supplier_invoice"><th>Factura proveedor</th><td colspan="3">{{ d.supplier_invoice }}</td></tr>
                <tr v-if="d.related"><th>Documento referencia</th><td colspan="3">{{ d.related }}</td></tr>
            </table>
            <table class="dates">
                <tr><th>{{ dateLabel }}</th><th v-if="d.due_date">Fecha de Vencimiento</th></tr>
                <tr><td>{{ d.date }}</td><td v-if="d.due_date">{{ d.due_date }}</td></tr>
            </table>
        </div>

        <table class="items">
            <thead>
                <tr><th class="w-i">Ítem</th><th class="r">Valor desc.</th><th class="r">Impto. Cargo</th><th class="r">Impto. Rete.</th><th class="r">Vr. Unitario</th><th class="desc">Descripción</th><th class="r">Cantidad</th><th class="r">Vr. Total</th></tr>
            </thead>
            <tbody>
                <tr v-for="(i, k) in d.items" :key="k">
                    <td class="c">{{ k + 1 }}</td><td class="r">{{ money(i.discount) }}</td><td class="r">{{ rate(i.charge_rate) }}</td><td class="r">{{ rate(i.withholding_rate) }}</td>
                    <td class="r">{{ money(i.price) }}</td><td class="desc">{{ i.description }}</td><td class="r">{{ qty(i.quantity) }}</td><td class="r">{{ money(Number(i.subtotal) + Number(i.discount)) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="foot">
            <div class="notes">
                <p class="h">Valor en Letras:</p>
                <p class="ind">{{ d.words }}</p>
                <p class="h mt">Condiciones de Pago:</p>
                <p v-for="(p, k) in d.payments" :key="k" class="pay">
                    <span>{{ p.name }}<template v-if="p.due_date"> - Cuota No. {{ String(k + 1).padStart(3, '0') }} vence el {{ String(p.due_date).slice(0, 10) }} por</template></span>
                    <span>$</span><span class="r">{{ money(p.value) }}</span>
                </p>
                <p class="h mt">Observaciones:</p>
                <p class="ind obs">{{ d.observations }}</p>
            </div>
            <table class="sums">
                <tr><td>Total Bruto</td><td class="r">{{ money(gross) }}</td></tr>
                <template v-if="Number(d.totals.discount)">
                    <tr><td>Descuentos</td><td class="r">-{{ money(d.totals.discount) }}</td></tr>
                    <tr><td>Subtotal</td><td class="r">{{ money(d.totals.subtotal) }}</td></tr>
                </template>
                <tr v-for="t in d.tax_lines" :key="t.name"><td>{{ t.name }}</td><td class="r">{{ money(t.value) }}</td></tr>
                <tr v-if="!d.tax_lines?.length"><td>IVA 0%</td><td class="r">0.00</td></tr>
                <tr v-for="t in d.withholding_lines" :key="'w' + t.name"><td>{{ t.name }}</td><td class="r">-{{ money(t.value) }}</td></tr>
                <tr v-for="t in d.retentions" :key="'r' + t.name"><td>{{ t.name }}</td><td class="r">-{{ money(t.value) }}</td></tr>
                <tr class="b"><td>Total a Pagar</td><td class="r">{{ money(d.totals.total) }}</td></tr>
            </table>
        </div>
        <div v-if="d.dian?.cufe || d.related_cuds" class="codes">
            <p v-if="d.dian?.cufe"><b>{{ d.type === 'DS' || d.type === 'NA' ? 'CUDS' : 'CUDE' }}:</b> {{ d.dian.cufe }}</p>
            <p v-if="d.related_cuds"><b>CUDS documento soporte:</b> {{ d.related_cuds }}</p>
        </div>
    </article>
</template>

<style scoped>
.psheet { position: relative; background: #fff; padding: 22px 34px 40px 10px; font: 10px/1.4 Arial, Helvetica, sans-serif; color: #222; }
.side { position: absolute; right: 6px; top: 70px; writing-mode: vertical-rl; transform: rotate(180deg); font-size: 8px; font-weight: bold; white-space: nowrap; }
.top { display: grid; grid-template-columns: 1fr auto 1fr; align-items: start; margin-bottom: 16px; }
.logo img { max-height: 70px; max-width: 150px; object-fit: contain; margin-left: 40px; }
.company { text-align: center; }
.company p, .company b { display: block; margin: 0; }
.box { justify-self: end; width: 125px; margin-top: 16px; border: 1px solid #bbb; padding: 10px 6px; text-align: center; font-size: 11px; }
.box p { margin: 1px 0; }
.ribbon { position: absolute; left: 0; top: 0; width: 90px; height: 90px; overflow: hidden; }
.ribbon span { position: absolute; left: -34px; top: 24px; width: 140px; transform: rotate(-45deg); background: #ec407a; color: #fff; text-align: center; font-size: 11px; padding: 3px 0; box-shadow: 0 1px 2px #0003; }
.heading { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; }
table { border-collapse: collapse; }
.party { width: 360px; }
.party th, .party td { border: 1px solid #ccc; padding: 2px 5px; text-align: left; font-weight: normal; }
.party th { width: 70px; font-weight: bold; }
.party td { min-width: 60px; }
.dates th, .dates td { border: 1px solid #ccc; padding: 4px 10px; text-align: center; width: 62px; }
.dates th { background: #eee; font-weight: bold; }
.dates td { padding: 12px 10px; }
.items { width: 100%; margin-top: 12px; }
.items th { background: #eee; font-weight: bold; padding: 4px 6px; border: 1px solid #ddd; text-align: center; }
.items td { padding: 3px 6px; border-bottom: 1px solid #eee; vertical-align: top; }
.items .desc { text-align: left; width: 34%; }
.w-i { width: 50px; }
.r { text-align: right; }
.c { text-align: center; }
.foot { display: flex; justify-content: space-between; gap: 30px; margin-top: 14px; }
.notes { flex: 1; }
.notes p { margin: 0; }
.h { font-weight: bold; }
.mt { margin-top: 14px !important; }
.ind { padding-left: 6px; }
.obs { white-space: pre-line; }
.pay { display: grid; grid-template-columns: 1fr 40px 120px; padding-left: 6px; }
.sums { width: 320px; align-self: flex-start; }
.sums td { border: 1px solid #ddd; padding: 3px 6px; }
.sums .b td { background: #eee; font-weight: bold; }
.codes { margin-top: 16px; word-break: break-all; }
.codes p { margin: 2px 0; }
@media print { .psheet { padding: 10px; } }
</style>
