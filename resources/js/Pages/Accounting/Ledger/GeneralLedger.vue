<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import LedgerFilters from '../../../Components/LedgerFilters.vue';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({ title: String, tabs: Array, filters: Object, types: Array, sellers: Array, rows: Array });
const money = (v) => (v ? Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00');
const pos = (v) => Math.max(v, 0);
const neg = (v) => Math.max(-v, 0);
const sum = (fn) => props.rows.reduce((a, r) => a + fn(r), 0);
const totals = computed(() => ({
    od: sum((r) => pos(r.opening)), oc: sum((r) => neg(r.opening)), d: sum((r) => r.debit), c: sum((r) => r.credit),
    bd: sum((r) => pos(r.balance)), bc: sum((r) => neg(r.balance)),
}));
</script>

<template>
    <Head :title="title" />
    <h1 class="pt-[10px] text-[24px] text-primary">{{ title }}</h1>
    <LedgerFilters url="/contabilidad/libro-mayor" :filters="filters" :sellers="sellers" :fields="['cuentas', 'fechas', 'vendedor']" />

    <section class="s-card mt-[14px]">
        <div class="s-table-wrap">
            <table class="s-table text-[12px]">
                <thead>
                    <tr><th rowspan="2">Código</th><th rowspan="2">Cuenta</th><th colspan="2" class="text-center">Saldo anterior</th><th colspan="2" class="text-center">Movimientos</th><th colspan="2" class="text-center">Nuevo saldo</th></tr>
                    <tr><th class="text-right">Débito</th><th class="text-right">Crédito</th><th class="text-right">Débito</th><th class="text-right">Crédito</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.code">
                        <td>{{ r.code }}</td><td>{{ r.name }}</td>
                        <td class="text-right">{{ money(pos(r.opening)) }}</td><td class="text-right">{{ money(neg(r.opening)) }}</td>
                        <td class="text-right">{{ money(r.debit) }}</td><td class="text-right">{{ money(r.credit) }}</td>
                        <td class="text-right">{{ money(pos(r.balance)) }}</td><td class="text-right">{{ money(neg(r.balance)) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length">
                    <tr class="font-semibold">
                        <td colspan="2" class="text-right">Totales</td>
                        <td class="text-right">{{ money(totals.od) }}</td><td class="text-right">{{ money(totals.oc) }}</td>
                        <td class="text-right">{{ money(totals.d) }}</td><td class="text-right">{{ money(totals.c) }}</td>
                        <td class="text-right">{{ money(totals.bd) }}</td><td class="text-right">{{ money(totals.bc) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <EmptyState v-if="!rows.length" />
    </section>
</template>
