<script setup>
import { Head } from '@inertiajs/vue3';
import LedgerFilters from '../../../Components/LedgerFilters.vue';
import Pager from '../../../Components/Pager.vue';
import EmptyState from '../../../Components/EmptyState.vue';

defineProps({ title: String, tabs: Array, filters: Object, types: Array, sellers: Array, lines: Object, totals: Object });
const money = (v) => (v === null || v === undefined ? '' : Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
</script>

<template>
    <Head :title="title" />
    <h1 class="pt-[10px] text-[24px] text-primary">{{ title }}</h1>
    <LedgerFilters url="/contabilidad/libro-auxiliar" :filters="filters" :types="types" :sellers="sellers" :fields="['cuentas', 'fechas', 'tercero', 'tipo', 'vendedor']" />

    <section class="s-card mt-[14px]">
        <div class="s-table-wrap">
            <table class="s-table text-[12px]">
                <thead>
                    <tr>
                        <th>Código cuenta contable</th><th>Cuenta contable</th><th>Identificación</th><th>Suc.</th><th>Nombre tercero</th><th>Vendedor</th>
                        <th>Comprobante</th><th>Fecha</th><th class="text-right">Saldo inicial</th><th class="text-right">Débito</th>
                        <th class="text-right">Crédito</th><th class="text-right">Nuevo saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in lines.data" :key="i">
                        <td>{{ l.code }}</td><td class="max-w-[200px] truncate">{{ l.account }}</td>
                        <td>{{ l.identification }}</td><td>{{ l.branch }}</td>
                        <td class="max-w-[200px] truncate">{{ l.contact }}</td><td class="max-w-[160px] truncate">{{ l.seller }}</td><td class="whitespace-nowrap">{{ l.document }}</td><td>{{ l.date }}</td>
                        <td class="text-right">{{ money(l.opening) }}</td><td class="text-right">{{ money(l.debit) }}</td>
                        <td class="text-right">{{ money(l.credit) }}</td><td class="text-right">{{ money(l.balance) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="lines.data.length">
                    <tr class="font-semibold"><td colspan="9" class="text-right">Totales del periodo</td><td class="text-right">{{ money(totals.debit) }}</td><td class="text-right">{{ money(totals.credit) }}</td><td /></tr>
                </tfoot>
            </table>
        </div>
        <EmptyState v-if="!lines.data.length" />
        <Pager :paginator="lines" />
    </section>
</template>
