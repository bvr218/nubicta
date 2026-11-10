<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import Lookup from './Lookup.vue';
import DownloadMenu from './DownloadMenu.vue';

const props = defineProps({
    url: { type: String, required: true },
    filters: { type: Object, default: () => ({}) },
    fields: { type: Array, default: () => ['cuentas', 'tercero', 'fechas'] },
    types: { type: Array, default: () => [] },
    sellers: { type: Array, default: () => [] },
});

const ACCOUNT_COLUMNS = [['code', 'Código'], ['name', 'Nombre'], ['category', 'Categoría']];
const CONTACT_COLUMNS = [['identification', 'Identificación'], ['name', 'Nombre']];
const f = reactive({ ...props.filters });
const clean = () => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== '' && v !== false && v !== undefined));
const apply = () => router.get(props.url, clean(), { preserveState: true });
const download = (formato) => (window.location.href = `${props.url}?${new URLSearchParams({ ...clean(), formato })}`);
const clear = () => router.get(props.url);
</script>

<template>
    <div class="flex justify-end gap-[8px]">
        <DownloadMenu @download="download" />
        <button type="button" class="s-btn s-btn-primary" @click="apply">Generar reporte</button>
    </div>
    <section class="s-card mt-[10px] grid grid-cols-2 gap-x-[40px] gap-y-[12px] px-[36px] py-[18px] text-[13px]">
        <div v-if="fields.includes('cuentas')" class="flex items-center gap-[10px]">
            <span class="w-[130px] text-right">Código contable</span>
            <Lookup v-model="f.cuenta_desde" :label="f.cuenta_desde ?? ''" url="/api/cuentas?transactional=0" value-key="code" :columns="ACCOUNT_COLUMNS" placeholder="Buscar" class="w-[150px]" @pick="(a) => (f.cuenta_desde = a?.code)" />
            <Lookup v-model="f.cuenta_hasta" :label="f.cuenta_hasta ?? ''" url="/api/cuentas?transactional=0" value-key="code" :columns="ACCOUNT_COLUMNS" placeholder="Buscar" class="w-[150px]" @pick="(a) => (f.cuenta_hasta = a?.code)" />
        </div>
        <div v-if="fields.includes('fechas')" class="flex items-center gap-[10px]">
            <span class="w-[130px] text-right">Fecha de elaboración</span>
            <input v-model="f.desde" type="date" class="s-input w-[160px]" />
            <input v-model="f.hasta" type="date" class="s-input w-[160px]" />
        </div>
        <div v-if="fields.includes('corte')" class="flex items-center gap-[10px]">
            <span class="w-[130px] text-right">Fecha de corte</span>
            <input v-model="f.hasta" type="date" class="s-input w-[160px]" />
        </div>
        <div v-if="fields.includes('tercero')" class="flex items-center gap-[10px]">
            <span class="w-[130px] text-right">Tercero</span>
            <Lookup v-model="f.tercero" :label="f.tercero ?? ''" url="/api/buscar/terceros" value-key="identification" :columns="CONTACT_COLUMNS" placeholder="Buscar" class="w-[310px]" />
        </div>
        <div v-if="fields.includes('tipo')" class="flex items-center gap-[10px]">
            <span class="w-[130px] text-right">Tipo de comprobante</span>
            <select v-model="f.tipo" class="s-input w-[160px]">
                <option :value="undefined">Todos</option>
                <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
            </select>
        </div>
        <div v-if="fields.includes('vendedor')" class="flex items-center gap-[10px]">
            <span class="w-[130px] text-right">Vendedor</span>
            <select v-model="f.vendedor" class="s-input w-[310px]">
                <option value="">Todos</option>
                <option v-for="s in sellers" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
            </select>
        </div>
        <slot :f="f" />
        <div class="col-span-2"><button type="button" class="text-[13px] font-semibold text-primary" @click="clear">Limpiar filtros</button></div>
    </section>
</template>
