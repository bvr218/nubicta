<script setup>
import { Head } from '@inertiajs/vue3';
import LedgerFilters from '../../../Components/LedgerFilters.vue';
import EmptyState from '../../../Components/EmptyState.vue';

defineProps({ title: String, tabs: Array, filters: Object, types: Array, sellers: Array, rows: Array, levels: Object, totals: Object });
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head :title="title" />
    <h1 class="pt-[10px] text-[24px] text-primary">{{ title }}</h1>
    <LedgerFilters url="/contabilidad/balance-prueba" :filters="filters" :sellers="sellers" :fields="['cuentas', 'fechas', 'tercero', 'vendedor']">
        <template #default="{ f }">
            <div class="flex items-center gap-[10px]">
                <span class="w-[130px] text-right">Nivel</span>
                <select v-model="f.nivel" class="s-input w-[160px]">
                    <option v-for="(n, k) in levels" :key="k" :value="k">{{ n }}</option>
                </select>
                <label class="flex items-center gap-[6px]"><input v-model="f.por_tercero" type="checkbox" true-value="1" :false-value="undefined" class="accent-primary" /> Por tercero</label>
            </div>
        </template>
    </LedgerFilters>

    <section class="s-card mt-[14px]">
        <div class="s-table-wrap">
            <table class="s-table text-[12px]">
                <thead>
                    <tr>
                        <th>Nivel</th><th>Código cuenta contable</th><th>Nombre cuenta contable</th><th>Identificación</th><th>Nombre tercero</th>
                        <th class="text-right">Saldo inicial</th><th class="text-right">Movimiento débito</th><th class="text-right">Movimiento crédito</th><th class="text-right">Saldo final</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="i" :class="{ 'font-semibold': r.level < 3 }">
                        <td>{{ levels[r.level] }}</td><td>{{ r.code }}</td><td>{{ r.name }}</td><td>{{ r.identification }}</td><td>{{ r.contact }}</td>
                        <td class="text-right">{{ money(r.opening) }}</td><td class="text-right">{{ money(r.debit) }}</td>
                        <td class="text-right">{{ money(r.credit) }}</td><td class="text-right">{{ money(r.balance) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length">
                    <tr class="font-semibold">
                        <td colspan="5" class="text-right">Total</td><td class="text-right">{{ money(totals.opening) }}</td><td class="text-right">{{ money(totals.debit) }}</td>
                        <td class="text-right">{{ money(totals.credit) }}</td><td class="text-right">{{ money(totals.balance) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <EmptyState v-if="!rows.length" />
    </section>
</template>
