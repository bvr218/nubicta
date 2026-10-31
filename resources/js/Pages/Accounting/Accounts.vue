<script setup>
import { nextTick, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Field from '../../Components/Field.vue';
import AccountNode from '../../Components/Accounting/AccountNode.vue';
import { confirmDialog } from '../../composables/dialog';

const props = defineProps({
    roots: { type: Array, required: true },
    categories: { type: Array, required: true },
    tabs: { type: Array, required: true },
});

const page = usePage();
const searchCode = ref('');
const searchName = ref('');
const results = ref(null);
const selected = ref(null);
const mode = ref(null); // edit | child
const form = ref({});
const errors = ref({});
const saving = ref(false);
const deleting = ref(false);
const deleteError = ref('');
const treeError = ref('');
const expanded = ref(false);
const tree = ref(null);
const treeKey = ref(0);
const tutorials = ref(false);

async function toggleAll() {
    if (!expanded.value && !tree.value) {
        const res = await fetch('/contabilidad/cuentas/arbol', { headers: { Accept: 'application/json' } });
        tree.value = await res.json();
    }
    expanded.value = !expanded.value;
}

let t = null;
function search() {
    clearTimeout(t);
    if (!searchCode.value && !searchName.value) {
        results.value = null;
        return;
    }
    t = setTimeout(async () => {
        const qs = new URLSearchParams({ code: searchCode.value, name: searchName.value });
        const res = await fetch(`/contabilidad/cuentas/buscar?${qs}`, { headers: { Accept: 'application/json' } });
        results.value = await res.json();
    }, 300);
}

function refreshTree(focusId) {
    clearTimeout(t);
    t = null;
    treeError.value = '';
    tree.value = null;
    results.value = null;
    searchCode.value = '';
    searchName.value = '';
    router.reload({
        only: ['roots'],
        preserveScroll: true,
        onSuccess: async () => {
            const res = await fetch('/contabilidad/cuentas/arbol', { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                treeError.value = 'No fue posible actualizar el árbol de cuentas.';
                return;
            }
            tree.value = await res.json();
            expanded.value = true;
            treeKey.value++;
            await nextTick();
            const targetId = focusId ?? props.roots[0]?.id;
            if (targetId) document.querySelector(`[data-account-id="${targetId}"]`)?.scrollIntoView({ block: 'center' });
        },
    });
}

async function select(node) {
    const res = await fetch(`/contabilidad/cuentas/${node.id}`, { headers: { Accept: 'application/json' } });
    selected.value = await res.json();
    deleteError.value = '';
    edit();
}

function edit() {
    const a = selected.value;
    mode.value = 'edit';
    errors.value = {};
    form.value = {
        code: a.code,
        name: a.name, category: a.category, due_type: a.due_type, fiscal_difference: a.fiscal_difference,
        transactional: a.transactional, requires_third_party: a.requires_third_party,
        requires_cost_center: a.requires_cost_center, active: a.active,
    };
}

function newChild() {
    const a = selected.value;
    mode.value = 'child';
    errors.value = {};
    form.value = {
        parent_id: a.id, code: a.code, name: '', category: a.category, due_type: null, fiscal_difference: false,
        transactional: true, requires_third_party: true, requires_cost_center: false, active: true,
    };
}

function save() {
    saving.value = true;
    const creating = mode.value === 'child';
    const selectedId = selected.value.id;
    const opts = {
        preserveScroll: true,
        onError: (e) => (errors.value = e),
        onSuccess: () => {
            const focusId = creating ? page.props.flash?.account_tree_focus_id : selectedId;
            refreshTree(focusId);
            select({ id: selectedId });
        },
        onFinish: () => (saving.value = false),
    };
    if (mode.value === 'child') router.post('/contabilidad/cuentas', form.value, opts);
    else router.put(`/contabilidad/cuentas/${selected.value.id}`, form.value, opts);
}

async function removeAccount() {
    if (!selected.value?.can_delete || deleting.value) return;
    if (!await confirmDialog(`¿Borrar la cuenta ${selected.value.code} - ${selected.value.name}? Esta acción no se puede deshacer.`)) return;

    deleting.value = true;
    const focusId = selected.value.parent_id;
    router.delete(`/contabilidad/cuentas/${selected.value.id}`, {
        preserveScroll: true,
        onError: (e) => (deleteError.value = e.account ?? 'No se pudo borrar la cuenta.'),
        onSuccess: () => {
            selected.value = null;
            mode.value = null;
            refreshTree(focusId);
        },
        onFinish: () => (deleting.value = false),
    });
}

const can = (perm) => {
    const p = page.props.auth?.permissions ?? [];
    return p.includes('*') || p.includes(perm);
};
const download = (base, f) => (window.location.href = base + (base.includes('?') ? '&' : '?') + 'formato=' + f);
</script>

<template>
    <Head title="Contabilidad" />
    <PageHeader title="Contabilidad" :tabs="tabs" active="Cuentas contables" />

    <section class="mt-[18px]">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <h2 class="whitespace-nowrap text-[22px] text-primary">Cuenta contable</h2>
            <div class="relative">
                <button type="button" class="s-btn s-btn-outline h-[26px] px-[10px] text-[11px]" @click="tutorials = !tutorials">Ver tutoriales <Icon icon="caret-down" /></button>
                <div v-if="tutorials" class="absolute left-0 top-[30px] z-20 w-[260px] rounded border border-line bg-white py-1 text-[12px] shadow-lg" @mouseleave="tutorials = false">
                    <a href="/ayuda?q=cuentas" class="block px-3 py-2 hover:bg-[#f0f9ff]">Cómo crear una cuenta contable</a>
                    <a href="/ayuda?q=plan" class="block px-3 py-2 hover:bg-[#f0f9ff]">Cómo editar el plan de cuentas</a>
                </div>
            </div>
            <button type="button" class="s-btn s-btn-outline ml-auto font-semibold" @click="download('/contabilidad/cuentas/excel', 'xlsx')"><Icon icon="file-excel" /> Descargar Excel</button>
        </div>

        <div class="s-card grid min-h-[480px] grid-cols-1 lg:grid-cols-[minmax(420px,45%)_1fr]">
            <div class="border-b border-line lg:border-b-0 lg:border-r">
                <button type="button" class="px-2 pt-2 text-[12px] text-primary" @click="toggleAll"><Icon :icon="expanded ? 'chevron-up' : 'chevron-down'" class="text-[10px]" /> {{ expanded ? 'Contraer todos los detalles' : 'Expandir todos los detalles' }}</button>
                <div class="grid grid-cols-[136px_1fr] border-b border-line text-[12px] text-muted">
                    <div class="px-2 py-2">Código</div>
                    <div class="border-l border-[#e6e9ec] px-2 py-2">Cuenta contable</div>
                </div>
                <div class="grid grid-cols-[136px_1fr] border-b border-line">
                    <div class="relative">
                        <Icon icon="magnifying-glass" class="absolute left-2 top-[10px] text-[11px] text-muted" />
                        <input v-model="searchCode" class="h-[32px] w-full pl-6 pr-1 text-[12px] outline-none" placeholder="Buscar por código" @input="search" />
                    </div>
                    <div class="relative border-l border-[#e6e9ec]">
                        <Icon icon="magnifying-glass" class="absolute left-2 top-[10px] text-[11px] text-muted" />
                        <input v-model="searchName" class="h-[32px] w-full pl-6 pr-1 text-[12px] outline-none" placeholder="Buscar por cuenta contable" @input="search" />
                    </div>
                </div>
                <div class="max-h-[640px] overflow-auto">
                    <p v-if="treeError" class="p-3 text-[12px] text-danger">{{ treeError }}</p>
                    <template v-if="results">
                        <AccountNode v-for="n in results" :key="`${treeKey}-s${n.id}`" :node="{ ...n, has_children: false }" :selected-id="selected?.id" @select="select" />
                        <p v-if="!results.length" class="p-4 text-muted">Sin resultados</p>
                    </template>
                    <template v-else-if="expanded && tree">
                        <AccountNode v-for="n in tree" :key="`${treeKey}-t${n.id}`" :node="n" :selected-id="selected?.id" expand-all @select="select" />
                    </template>
                    <AccountNode v-for="n in roots" v-else :key="`${treeKey}-${n.id}`" :node="n" :selected-id="selected?.id" @select="select" />
                </div>
            </div>

            <div class="p-[24px]">
                <p v-if="!selected" class="mx-auto mt-[90px] max-w-[520px] text-center text-[26px] leading-snug text-[#c4c8cc]">
                    Para crear o editar, selecciona una cuenta contable desplegando los grupos de cuentas ubicados en la parte izquierda.
                </p>
                <template v-else>
                    <div class="mb-4 flex items-center gap-3">
                        <div>
                            <p class="text-[12px] text-muted">{{ selected.level_name }} · Naturaleza {{ selected.nature }}</p>
                            <h3 class="text-[17px] font-bold text-ink">
                                {{ mode === 'child' ? 'Nueva cuenta en ' + selected.code : selected.code + ' - ' + selected.name }}
                            </h3>
                        </div>
                        <button v-if="mode === 'edit' && selected.level < 5 && can('contabilidad.crear')" type="button" class="s-btn s-btn-outline ml-auto" @click="newChild">
                            <Icon icon="plus" /> Crear subcuenta
                        </button>
                        <button v-if="mode === 'child'" type="button" class="s-btn s-btn-outline ml-auto" @click="edit">Cancelar</button>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <Field v-if="mode === 'child' || mode === 'edit'" label="Código" :required="mode === 'child' || selected.can_change_code" :error="errors.code">
                            <input v-model="form.code" class="s-input" inputmode="numeric" :disabled="mode === 'edit' && !selected.can_change_code" />
                            <p v-if="mode === 'edit' && !selected.can_change_code" class="mt-1 text-[11px] text-muted">{{ selected.code_change_reason }}</p>
                        </Field>
                        <Field label="Nombre" required :error="errors.name" :class="{ 'col-span-2': mode !== 'child' }">
                            <input v-model="form.name" class="s-input" />
                        </Field>
                        <Field label="Categoría" :error="errors.category">
                            <select v-model="form.category" class="s-input">
                                <option :value="null">Sin categoría</option>
                                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </Field>
                        <Field label="Maneja vencimientos">
                            <select v-model="form.due_type" class="s-input">
                                <option :value="null">No maneja vencimiento</option>
                                <option value="cartera">Con vencimiento en cartera</option>
                                <option value="proveedores">Con vencimiento en proveedores</option>
                            </select>
                        </Field>
                        <label class="flex items-center gap-2"><input v-model="form.transactional" type="checkbox" class="accent-primary" /> Cuenta transaccional (recibe movimientos)</label>
                        <label class="flex items-center gap-2"><input v-model="form.requires_third_party" type="checkbox" class="accent-primary" /> Exige tercero</label>
                        <label class="flex items-center gap-2"><input v-model="form.requires_cost_center" type="checkbox" class="accent-primary" /> Exige centro de costo</label>
                        <label class="flex items-center gap-2"><input v-model="form.fiscal_difference" type="checkbox" class="accent-primary" /> Diferencia fiscal</label>
                        <label class="flex items-center gap-2"><input v-model="form.active" type="checkbox" class="accent-primary" /> Activa</label>
                    </div>
                    <p v-if="selected.relation && mode === 'edit'" class="mt-4 text-[12px] text-muted">Relación con: {{ selected.relation }}</p>

                    <p v-if="deleteError" class="mt-4 text-[12px] text-danger">{{ deleteError }}</p>
                    <div v-if="can(mode === 'child' ? 'contabilidad.crear' : 'contabilidad.editar')" class="mt-6 flex justify-between">
                        <button v-if="mode === 'edit'" type="button" class="s-btn s-btn-outline text-danger" :disabled="deleting || !selected.can_delete" :title="selected.code_change_reason" @click="removeAccount">
                            <Icon icon="trash" /> Borrar cuenta
                        </button>
                        <button type="button" class="s-btn s-btn-primary w-[120px]" :disabled="saving || deleting" @click="save">Guardar</button>
                    </div>
                </template>
            </div>
        </div>
    </section>

</template>
