<script setup>
import NumInput from '../../Components/Forms/NumInput.vue';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Lookup from '../../Components/Lookup.vue';

const props = defineProps({
    contact: { type: Object, default: null },
    role: { type: String, default: null },
    cityLabel: { type: String, default: '' },
    defaultMunicipality: { type: String, default: null },
    identificationTypes: { type: Object, required: true },
    countries: { type: Object, required: true },
    responsibilities: { type: Object, required: true },
    sellers: { type: Array, required: true },
    priceLists: { type: Array, required: true },
});

const c = props.contact ?? {};
const form = useForm({
    is_customer: c.is_customer ?? props.role === 'cliente',
    is_supplier: c.is_supplier ?? props.role === 'proveedor',
    is_employee: c.is_employee ?? props.role === 'empleado',
    is_other: c.is_other ?? props.role === 'otro',
    person_type: c.person_type ?? 'natural',
    identification_type: c.identification_type ?? '13',
    identification: c.identification ?? '',
    dv: c.dv ?? '',
    branch_office: c.branch_office ?? 0,
    first_name: c.first_name ?? '',
    last_name: c.last_name ?? '',
    business_name: c.business_name ?? '',
    trade_name: c.trade_name ?? '',
    vat_responsible: c.vat_responsible ?? false,
    fiscal_responsibilities: c.fiscal_responsibilities ?? ['R-99-PN'],
    address: c.address ?? '',
    country_code: c.country_code ?? 'CO',
    foreign_city: c.foreign_city ?? '',
    department_code: c.department_code ?? null,
    municipality_code: c.municipality_code ?? props.defaultMunicipality ?? '',
    postal_code: c.postal_code ?? '',
    phone_indicative: c.phone_indicative ?? '608',
    phone: c.phone ?? '',
    phone_extension: c.phone_extension ?? '',
    mobile: c.mobile ?? '',
    billing_first_name: c.billing_first_name ?? '',
    billing_last_name: c.billing_last_name ?? '',
    email: c.email ?? '',
    seller_id: c.seller_id ?? null,
    collector_id: c.collector_id ?? null,
    price_list_id: c.price_list_id ?? null,
    credit_limit: c.credit_limit ?? 0,
    credit_days: c.credit_days ?? 0,
    observations: c.observations ?? '',
    active: c.active ?? true,
    people: (c.people ?? []).map((p) => ({ first_name: p.first_name, last_name: p.last_name, email: p.email, phone_indicative: p.phone_indicative, phone: p.phone, position: p.position })),
});

const city = ref(props.cityLabel);
if (!props.contact && !form.department_code && props.defaultMunicipality) form.department_code = props.defaultMunicipality.slice(0, 2);
const open = ref({ contactos: !!form.people.length, vendedor: false, observaciones: !!form.observations });
const isCompany = computed(() => form.person_type === 'juridica');
const abroad = computed(() => form.country_code && form.country_code !== 'CO');
const foreignId = computed(() => ['21', '22', '41', '42', '47', '50'].includes(String(form.identification_type)));
const regime = computed({
    get: () => (form.vat_responsible ? '48' : '49'),
    set: (v) => (form.vat_responsible = v === '48'),
});

function onPersonType() {
    form.identification_type = isCompany.value ? '31' : '13';
}
function pickCity(m) {
    form.department_code = m?.department_code ?? null;
}
function onCountry() {
    if (abroad.value) {
        form.municipality_code = '';
        form.department_code = null;
        city.value = '';
    } else {
        form.foreign_city = '';
    }
}
async function autocompleteDv() {
    if (!form.identification) return;
    const res = await fetch(`/api/terceros/dv?identificacion=${encodeURIComponent(form.identification)}`);
    form.dv = String((await res.json()).dv ?? '');
}
function addPerson() {
    form.people.push({ first_name: '', last_name: '', email: '', phone_indicative: '', phone: '', position: '' });
    open.value.contactos = true;
}

const backUrl = computed(() => {
    const r = props.role ?? (form.is_customer ? 'cliente' : form.is_supplier ? 'proveedor' : null);
    return '/terceros' + (r ? `?tipo=${r}` : '');
});
function submit() {
    if (props.contact) form.put(`/terceros/${props.contact.id}`, { preserveScroll: true });
    else form.post('/terceros');
}
const types = [
    { key: 'is_customer', label: 'Clientes', text: 'Personas o empresas a las cuales necesitas generarles una cotización y/o una factura de venta' },
    { key: 'is_supplier', label: 'Proveedores', text: 'Todas las personas o empresas a las cuales les compras bienes o servicios' },
    { key: 'is_other', label: 'Otros', text: 'Por ejemplo: Fondos de salud y pensión, bancos, etc.' },
];
</script>

<template>
    <Head :title="contact ? 'Editar tercero' : 'Crear un tercero'" />
    <div class="flex items-center pt-[18px]">
        <h1 class="text-[22px] text-primary">{{ contact ? 'Editar tercero' : 'Crear un tercero' }}</h1>
        <div class="ml-auto flex gap-3">
            <Link :href="backUrl" class="s-btn s-btn-cancel w-[120px]">Cancelar</Link>
            <button type="button" class="s-btn s-btn-green w-[118px] font-bold" :disabled="form.processing" @click="submit">Guardar</button>
        </div>
    </div>
    <p class="mt-[26px] text-muted">Todos los campos marcados con <span class="text-danger">*</span> son obligatorios para la creación del tercero</p>

    <form class="mt-[14px] flex flex-col gap-[18px]" @submit.prevent="submit">
        <section class="s-card px-[22px] pb-[30px] pt-[20px]">
            <h2 class="s-section-title">Tipo de tercero</h2>
            <div class="mt-1 grid grid-cols-3 gap-6">
                <label v-for="t in types" :key="t.key" class="block cursor-pointer">
                    <span class="flex items-center gap-1 text-primary"><input v-model="form[t.key]" type="checkbox" class="accent-primary" /> {{ t.label }}</span>
                    <span class="mt-1 block text-[11px] text-muted">{{ t.text }}</span>
                </label>
            </div>
            <label v-if="form.is_employee" class="mt-3 flex items-center gap-1 text-primary">
                <input v-model="form.is_employee" type="checkbox" class="accent-primary" /> Empleado
            </label>
        </section>

        <div class="grid grid-cols-2 gap-[14px]">
            <section class="s-card px-[22px] py-[20px]">
                <h2 class="s-section-title"><span class="text-danger">*</span> &nbsp;Datos básicos</h2>
                <div class="mt-3 grid grid-cols-2 gap-x-8">
                    <div class="flex flex-col gap-5 border-r border-line pr-6">
                        <label><span class="s-line-label blue">Tipo</span>
                            <select v-model="form.person_type" class="s-line" @change="onPersonType">
                                <option value="natural">Es persona</option>
                                <option value="juridica">Es empresa</option>
                            </select>
                        </label>
                        <label>
                            <select v-model="form.identification_type" class="s-line" :class="{ 'is-invalid': form.errors.identification_type }">
                                <option v-for="(n, k) in identificationTypes" :key="k" :value="k">{{ n }}</option>
                            </select>
                            <span class="s-line-label !text-ink">* Tipo de identificación</span>
                        </label>
                        <div class="flex gap-3">
                            <label class="flex-1">
                                <input v-model="form.identification" class="s-line" :class="{ 'is-invalid': form.errors.identification }" placeholder="* Identificación" />
                            </label>
                            <label class="w-[60px]"><input v-model="form.dv" maxlength="1" class="s-line" placeholder="Dv" /></label>
                        </div>
                        <p v-if="form.errors.identification" class="s-error -mt-4">{{ form.errors.identification }}</p>
                        <p v-if="foreignId" class="-mt-3 text-[12px] text-muted">Este documento es de un no residente. Elige el país y la ciudad; si dejas Colombia, el documento soporte lo rechaza la DIAN.</p>
                        <button type="button" class="-mt-3 text-center text-[12px] font-bold text-muted hover:text-primary" @click="autocompleteDv">
                            Autocompletar datos <Icon icon="circle-question" class="text-primary" />
                        </button>
                        <label><span class="s-line-label">Código de la sucursal</span>
                            <input v-model="form.branch_office" type="number" min="0" class="s-line" />
                        </label>
                    </div>
                    <div class="flex flex-col gap-5">
                        <template v-if="!isCompany">
                            <label><input v-model="form.first_name" class="s-line" :class="{ 'is-invalid': form.errors.first_name }" placeholder="* Nombres" /></label>
                            <label><input v-model="form.last_name" class="s-line" :class="{ 'is-invalid': form.errors.last_name }" placeholder="* Apellidos" /></label>
                        </template>
                        <label v-else><input v-model="form.business_name" class="s-line" :class="{ 'is-invalid': form.errors.business_name }" placeholder="* Razón social" /></label>
                        <label><input v-model="form.trade_name" class="s-line" placeholder="Nombre comercial" /></label>
                        <label><span class="s-line-label">País</span>
                            <select v-model="form.country_code" class="s-line" @change="onCountry">
                                <option v-for="(name, code) in countries" :key="code" :value="code">{{ name }}</option>
                            </select>
                        </label>
                        <label v-if="!abroad"><span class="s-line-label">Ciudad</span>
                            <Lookup v-model="form.municipality_code" v-model:label="city" url="/api/municipios" value-key="code" input-class="s-line" placeholder="Ciudad" @pick="pickCity" />
                        </label>
                        <label v-else><span class="s-line-label">Ciudad</span>
                            <input v-model="form.foreign_city" class="s-line" :class="{ 'is-invalid': form.errors.foreign_city }" placeholder="Ciudad" />
                        </label>
                        <p v-if="form.errors.municipality_code || form.errors.foreign_city" class="s-error -mt-4">{{ form.errors.municipality_code || form.errors.foreign_city }}</p>
                        <label><input v-model="form.address" class="s-line" placeholder="Dirección" /></label>
                    </div>
                </div>
                <div class="mt-6 flex items-end gap-3">
                    <label class="w-[70px]"><span class="s-line-label">Indicativo</span><input v-model="form.phone_indicative" class="s-line" /></label>
                    <label class="flex-1"><input v-model="form.phone" class="s-line" placeholder="# de Teléfono" /></label>
                    <label class="w-[70px]"><input v-model="form.phone_extension" class="s-line" placeholder="Extensión" /></label>
                </div>
                <p v-for="e in ['first_name', 'last_name', 'business_name']" :key="e" class="s-error">{{ form.errors[e] }}</p>
            </section>

            <section class="s-card px-[22px] py-[20px]">
                <h2 class="s-section-title">Datos para facturación y envío <Icon icon="circle-question" class="text-[13px] text-primary" /></h2>
                <div class="mt-3 grid grid-cols-[1fr_230px] gap-x-6">
                    <div class="flex flex-col gap-5 border-r border-line pr-6">
                        <input v-model="form.billing_first_name" class="s-line" placeholder="Nombres del contacto" />
                        <input v-model="form.billing_last_name" class="s-line" placeholder="Apellidos del contacto" />
                        <div>
                            <input v-model="form.email" class="s-line" :class="{ 'is-invalid': form.errors.email }" placeholder="Correo electrónico cuando aplique" />
                            <p v-if="form.errors.email" class="s-error">{{ form.errors.email }}</p>
                        </div>
                        <select v-model="regime" class="s-line">
                            <option value="49">No responsable de IVA</option>
                            <option value="48">Responsable de IVA</option>
                        </select>
                        <div class="flex gap-3">
                            <label class="w-[70px]"><span class="s-line-label">Indicativo</span><input value="+57" disabled class="s-line text-muted" /></label>
                            <label class="flex-1 self-end"><input v-model="form.mobile" class="s-line" placeholder="# Celular" /></label>
                        </div>
                        <input v-model="form.postal_code" class="s-line" placeholder="Código postal" />
                        <label class="flex items-center gap-2 text-[12px] text-muted">
                            <input type="checkbox" checked disabled class="accent-ok" /> Contacto marcado como <b>Pagador</b>.
                        </label>
                    </div>
                    <div>
                        <p class="text-primary">Responsabilidad fiscal</p>
                        <p class="mt-4 text-[12px] text-muted">Verifica la responsabilidad en el RUT de tu cliente, mínimo asignar R-99-PN</p>
                        <label v-for="(n, k) in responsibilities" :key="k" class="mt-3 grid cursor-pointer grid-cols-[22px_54px_1fr] items-start text-[11px]">
                            <input v-model="form.fiscal_responsibilities" type="checkbox" :value="k" class="mt-[2px] accent-ok" />
                            <b>{{ k }}</b><b>{{ n }}</b>
                        </label>
                        <p v-if="form.errors.fiscal_responsibilities" class="s-error">Asigna mínimo una responsabilidad.</p>
                    </div>
                </div>
            </section>
        </div>

        <section class="s-card px-[22px] py-[20px]">
            <button type="button" class="s-section-title flex items-center gap-3" @click="open.contactos = !open.contactos">
                <Icon :icon="open.contactos ? 'chevron-down' : 'chevron-right'" class="text-[12px] text-primary" /> Contactos
            </button>
            <div v-if="open.contactos" class="mt-4">
                <div v-for="(p, i) in form.people" :key="i" class="mb-4 grid grid-cols-[1fr_1fr_1.4fr_70px_1fr_1fr_30px] items-end gap-3">
                    <input v-model="p.first_name" class="s-line" placeholder="* Nombres" :class="{ 'is-invalid': form.errors[`people.${i}.first_name`] }" />
                    <input v-model="p.last_name" class="s-line" placeholder="Apellidos" />
                    <input v-model="p.email" class="s-line" placeholder="Correo electrónico" />
                    <input v-model="p.phone_indicative" class="s-line" placeholder="Indicativo" />
                    <input v-model="p.phone" class="s-line" placeholder="Teléfono" />
                    <input v-model="p.position" class="s-line" placeholder="Cargo" />
                    <button type="button" class="text-danger" title="Eliminar" @click="form.people.splice(i, 1)"><Icon icon="trash" /></button>
                </div>
                <button type="button" class="text-[13px] font-bold text-[#7ac142] underline" @click="addPerson">Agregar contacto</button>
            </div>
        </section>

        <div class="grid grid-cols-2 gap-[14px]">
            <section class="s-card px-[22px] py-[20px]">
                <button type="button" class="s-section-title flex items-center gap-3" @click="open.vendedor = !open.vendedor">
                    <Icon :icon="open.vendedor ? 'chevron-down' : 'chevron-right'" class="text-[12px] text-primary" /> Vendedor y cobrador
                </button>
                <div v-if="open.vendedor" class="mt-4 grid grid-cols-2 gap-5">
                    <label><span class="s-line-label blue">Vendedor</span>
                        <select v-model="form.seller_id" class="s-line"><option :value="null">Sin asignar</option><option v-for="s in sellers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    </label>
                    <label><span class="s-line-label blue">Cobrador</span>
                        <select v-model="form.collector_id" class="s-line"><option :value="null">Sin asignar</option><option v-for="s in sellers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    </label>
                    <label><span class="s-line-label blue">Lista de precios</span>
                        <select v-model="form.price_list_id" class="s-line"><option :value="null">Predeterminada</option><option v-for="l in priceLists" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label><span class="s-line-label blue">Cupo de crédito</span><NumInput v-model="form.credit_limit" plain class="s-line" /></label>
                        <label><span class="s-line-label blue">Días de crédito</span><input v-model="form.credit_days" type="number" min="0" class="s-line" /></label>
                    </div>
                    <label class="flex items-center gap-2"><input v-model="form.active" type="checkbox" class="accent-primary" /> Tercero activo</label>
                </div>
            </section>
            <section class="s-card px-[22px] py-[20px]">
                <button type="button" class="s-section-title flex items-center gap-3" @click="open.observaciones = !open.observaciones">
                    <Icon :icon="open.observaciones ? 'chevron-down' : 'chevron-right'" class="text-[12px] text-primary" /> Observaciones
                </button>
                <textarea v-if="open.observaciones" v-model="form.observations" rows="4" class="s-input mt-4" />
            </section>
        </div>
    </form>
</template>
