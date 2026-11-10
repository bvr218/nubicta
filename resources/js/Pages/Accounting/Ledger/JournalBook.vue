<script setup>
import { Head } from '@inertiajs/vue3';
import LedgerFilters from '../../../Components/LedgerFilters.vue';
import Pager from '../../../Components/Pager.vue';
import EmptyState from '../../../Components/EmptyState.vue';

defineProps({ title: String, tabs: Array, filters: Object, types: Array, sellers: Array, lines: Object, totals: Object });
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head :title="title" />
    <h1 class="pt-[10px] text-[24px] text-primary">{{ title }}</h1>
    <LedgerFilters url="/contabilidad/libro-diario" :filters="filters" :types="types" :sellers="sellers" :fields="['cuentas', 'fechas', 'tipo', 'vendedor']" />

    <section class="s-card mt-[14px]">
        <div class="s-table-wrap">
            <table class="s-table text-[12px]">
                <thead>
                    <tr><th>Fecha</th><th>Tipo comprobante</th><th>Código cuenta</th><th>Cuenta</th><th class="text-right">Comprobantes</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in lines.data" :key="i">
                        <td>{{ l.date }}</td><td>{{ l.type }}</td><td>{{ l.code }}</td><td>{{ l.account }}</td>
                        <td class="text-right">{{ l.documents }}</td><td class="text-right">{{ money(l.debit) }}</td><td class="text-right">{{ money(l.credit) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="lines.data.length">
                    <tr class="font-semibold"><td colspan="5" class="text-right">Totales del periodo</td><td class="text-right">{{ money(totals.debit) }}</td><td class="text-right">{{ money(totals.credit) }}</td></tr>
                </tfoot>
            </table>
        </div>
        <EmptyState v-if="!lines.data.length" />
        <Pager :paginator="lines" />
    </section>
</template>
