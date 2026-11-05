<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    company: { type: Object, required: true },
    logoUrl: { type: String, default: null },
    departments: { type: Array, required: true },
    municipalities: { type: Array, required: true },
    catalogs: { type: Object, required: true },
    municipalityName: { type: String, default: null },
});

const c = props.company;
const form = useForm({
    person_type: c.person_type ?? 'juridica',
    identification_type: c.identification_type ?? '31',
    nit: c.nit === '000000000' ? '' : c.nit,
    business_name: c.business_name ?? '',
    trade_name: c.trade_name ?? '',
    show_trade_name: !!c.show_trade_name,
    first_name: c.first_name ?? '',
    last_name: c.last_name ?? '',
    tax_regime: c.tax_regime ?? '48',
    fiscal_responsibilities: c.fiscal_responsibilities ?? [],
    tributes: c.tributes ?? ['01'],
    ciiu: c.ciiu ?? '',
    ica_rate: c.ica_rate ?? '',
    sells_medicines_eps: !!c.sells_medicines_eps,
    handles_aiu: !!c.handles_aiu,
    two_charge_taxes: !!c.two_charge_taxes,
    iva_withholding_agent: !!c.iva_withholding_agent,
    ad_valorem: !!c.ad_valorem,
    foreign_currency: !!c.foreign_currency,
    address: c.address ?? '',
    department_code: c.department_code ?? '',
    municipality_code: c.municipality_code ?? '',
    postal_code: c.postal_code ?? '',
    phone: c.phone ?? '',
    phone2: c.phone2 ?? '',
    phone_ext: c.phone_ext ?? '',
    email: c.email ?? '',
    contact_name: c.contact_name ?? '',
    website: c.website ?? '',
    legal_rep_first_name: c.legal_rep_first_name ?? '',
    legal_rep_last_name: c.legal_rep_last_name ?? '',
    legal_rep_identification_type: c.legal_rep_identification_type ?? '13',
    legal_rep_identification: c.legal_rep_identification ?? '',
    has_partners: !!c.has_partners,
    logo: null,
});

const towns = ref(props.municipalities);
const preview = ref(props.logoUrl);
const warning = ref(false);
let warningTimer = null;
const logoFocus = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('seccion') === 'logo';
if (logoFocus) setTimeout(() => document.getElementById('logo')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 300);
const open = ref({ general: true, tributarios: true, moneda: true, representante: true });
onBeforeUnmount(() => clearTimeout(warningTimer));

watch(
    () => form.department_code,
    async (dep, old) => {
        if (!dep || dep === old) return;
        const res = await fetch(`/configuracion/municipios/${dep}`, { headers: { Accept: 'application/json' } });
        towns.value = await res.json();
        if (!towns.value.find((t) => t.code === form.municipality_code)) form.municipality_code = '';
    },
);

const dv = computed(() => {
    const weights = [71, 67, 59, 53, 47, 43, 41, 37, 29, 23, 19, 17, 13, 7, 3];
    const n = (form.nit || '').replace(/\D/g, '');
    if (!n || n.length > 15 || form.identification_type !== '31') return '';
    const off = weights.length - n.length;
    let sum = 0;
    [...n].forEach((d, i) => (sum += Number(d) * weights[off + i]));
    const r = sum % 11;
    return String(r > 1 ? 11 - r : r);
});

const regimeLabel = (k, n) => `${k === '48' ? '002' : '001'} - ${n}`;
const toggle = (list, k) => (list.includes(k) ? list.splice(list.indexOf(k), 1) : list.push(k));

function onLogo(e) {
    const file = e.target.files[0];
    form.logo = file ?? null;
    preview.value = file ? URL.createObjectURL(file) : props.logoUrl;
}

function submit() {
    if (form.person_type === 'natural') form.business_name = `${form.first_name} ${form.last_name}`.trim();
    form.post('/configuracion/empresa', {
        forceFormData: true,
        preserveScroll: true,
        onError: () => {
            warning.value = true;
            clearTimeout(warningTimer);
            warningTimer = setTimeout(() => (warning.value = false), 5000);
        },
        onSuccess: () => {
            warning.value = false;
            clearTimeout(warningTimer);
        },
    });
}
</script>

<template>
    <Head title="Datos de la empresa" />
    <h1 class="pt-[10px] text-[24px] font-semibold text-primary">Datos de la empresa</h1>
    <div class="mt-3 flex">
        <button type="button" class="bg-[#7ac142] px-3 py-[7px] text-[13px] font-semibold text-white" :disabled="form.processing" @click="submit">Guardar</button>
        <Link href="/configuracion" class="bg-primary px-3 py-[7px] text-[13px] font-semibold text-white">Volver</Link>
    </div>

    <form class="mt-3 border border-line bg-white text-[13px]" @submit.prevent="submit">
        <!-- Datos generales -->
        <button type="button" class="s-form-section" @click="open.general = !open.general">
            <Icon :icon="open.general ? 'chevron-down' : 'chevron-right'" class="text-[10px]" /> Datos generales
        </button>
        <div v-show="open.general" class="s-form-rows">
            <div class="s-row"><span>Tipo razón social</span>
                <div class="flex gap-6">
                    <label class="flex items-center gap-2"><input v-model="form.person_type" type="radio" value="natural" class="accent-primary" /> Es persona</label>
                    <label class="flex items-center gap-2"><input v-model="form.person_type" type="radio" value="juridica" class="accent-primary" /> Empresa</label>
                </div>
            </div>
            <template v-if="form.person_type === 'natural'">
                <div class="s-row"><span>Nombre</span><div><input v-model="form.first_name" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.first_name }" :aria-invalid="!!form.errors.first_name" /><p v-if="form.errors.first_name" class="s-error">{{ form.errors.first_name }}</p></div></div>
                <div class="s-row"><span>Apellidos</span><div><input v-model="form.last_name" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.last_name }" :aria-invalid="!!form.errors.last_name" /><p v-if="form.errors.last_name" class="s-error">{{ form.errors.last_name }}</p></div></div>
            </template>
            <div v-else class="s-row"><span>Razón social</span><div><input v-model="form.business_name" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.business_name }" :aria-invalid="!!form.errors.business_name" /><p v-if="form.errors.business_name" class="s-error">{{ form.errors.business_name }}</p></div></div>
            <div v-if="c.siigo_serial" class="s-row"><span>Serial</span><span class="text-ink">{{ c.siigo_serial }}</span></div>
            <div class="s-row"><span>Tipo Identificación</span>
                <div><select v-model="form.identification_type" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.identification_type }" :aria-invalid="!!form.errors.identification_type">
                    <option v-for="(n, k) in catalogs.identification_types" :key="k" :value="k">{{ n }}</option>
                </select><p v-if="form.errors.identification_type" class="s-error">{{ form.errors.identification_type }}</p></div>
            </div>
            <div class="s-row"><span>Identificación</span>
                <div class="flex items-center gap-4">
                    <input v-model="form.nit" class="s-input w-[180px]" :class="{ 'is-invalid': form.errors.nit }" :aria-invalid="!!form.errors.nit" inputmode="numeric" />
                    <template v-if="form.identification_type === '31'"><span>Dígito Verificación</span><input :value="dv" class="s-input w-[46px] bg-[#e9ecef]" readonly /></template>
                </div>
            </div>
            <p v-if="form.errors.nit" class="s-error ml-[310px]">{{ form.errors.nit }}</p>
            <div class="s-row items-start"><span>Nombre comercial</span>
                <div>
                    <input v-model="form.trade_name" class="s-input w-[390px]" />
                    <label class="mt-1 flex items-center gap-2"><input v-model="form.show_trade_name" type="checkbox" class="accent-primary" /> Mostrar en representación gráfica de comprobantes electrónicos</label>
                </div>
            </div>
            <div id="logo" class="s-row" :class="{ 'rounded ring-2 ring-primary': logoFocus }"><span>Logo para tus documentos</span>
                <div class="flex items-center gap-3">
                    <label class="cursor-pointer font-semibold text-primary">Cambiar logo <Icon icon="cloud" /><input type="file" accept="image/*" class="hidden" @change="onLogo" /></label>
                    <span class="text-ink-soft">Se mostrará en los diferentes documentos y facturas</span>
                    <img v-if="preview" :src="preview" alt="Logo" class="h-[28px] object-contain" />
                </div>
            </div>
            <div class="s-row"><span>Departamento</span>
                <div><select v-model="form.department_code" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.department_code }" :aria-invalid="!!form.errors.department_code">
                    <option value="">Selecciona</option>
                    <option v-for="d in departments" :key="d.code" :value="d.code">{{ d.name }}</option>
                </select><p v-if="form.errors.department_code" class="s-error">{{ form.errors.department_code }}</p></div>
            </div>
            <div class="s-row"><span>Ciudad</span>
                <div><select v-model="form.municipality_code" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.municipality_code }" :aria-invalid="!!form.errors.municipality_code">
                    <option value="">Selecciona</option>
                    <option v-for="m in towns" :key="m.code" :value="m.code">{{ m.name }}</option>
                </select><p v-if="form.errors.municipality_code" class="s-error">{{ form.errors.municipality_code }}</p></div>
            </div>
            <div class="s-row items-start"><span>Código postal</span>
                <div><input v-model="form.postal_code" class="s-input w-[390px]" /><a href="https://visor.codigopostal.gov.co" target="_blank" class="mt-1 block text-primary underline">Consultar códigos postales</a></div>
            </div>
            <div class="s-row"><span>Dirección</span><div><input v-model="form.address" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.address }" :aria-invalid="!!form.errors.address" /><p v-if="form.errors.address" class="s-error">{{ form.errors.address }}</p></div></div>
            <div class="s-row"><span>Tipo de régimen IVA</span>
                <div><select v-model="form.tax_regime" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.tax_regime }" :aria-invalid="!!form.errors.tax_regime">
                    <option v-for="(n, k) in catalogs.tax_regimes" :key="k" :value="k">{{ regimeLabel(k, n) }}</option>
                </select><p v-if="form.errors.tax_regime" class="s-error">{{ form.errors.tax_regime }}</p></div>
            </div>
            <div class="s-row"><span>Correo contacto</span><div><input v-model="form.email" type="email" class="s-input w-[390px]" :class="{ 'is-invalid': form.errors.email }" :aria-invalid="!!form.errors.email" /><p v-if="form.errors.email" class="s-error">{{ form.errors.email }}</p></div></div>
            <div class="s-row"><span>Contacto</span><input v-model="form.contact_name" class="s-input w-[390px]" /></div>
            <div class="s-row"><span>Página WEB</span><input v-model="form.website" class="s-input w-[390px]" /></div>
            <div class="s-row"><span>Teléfono</span>
                <div class="flex gap-3">
                    <input v-model="form.phone" class="s-input w-[130px]" :class="{ 'is-invalid': form.errors.phone }" :aria-invalid="!!form.errors.phone" />
                    <input v-model="form.phone2" class="s-input w-[120px]" />
                    <input v-model="form.phone_ext" class="s-input w-[110px]" placeholder="Ext" />
                </div>
            </div>
            <p v-if="form.errors.phone" class="s-error ml-[310px]">{{ form.errors.phone }}</p>
        </div>

        <!-- Datos tributarios -->
        <button type="button" class="s-form-section" @click="open.tributarios = !open.tributarios">
            <Icon :icon="open.tributarios ? 'chevron-down' : 'chevron-right'" class="text-[10px]" /> Datos tributarios
        </button>
        <div v-show="open.tributarios" class="s-form-rows">
            <div class="s-row items-start"><span>Código actividad económica</span>
                <div class="w-[390px]">
                    <input v-model="form.ciiu" class="s-input" placeholder="Ej: 4620" />
                    <p class="mt-1 text-ink-soft">El código CIIU registrado debe corresponder con la realidad comercial y tributaria de su negocio y la información reportada ante las autoridades, conforme con la normatividad aplicable.</p>
                </div>
            </div>
            <div class="s-row"><span>Venta de medicamentos, insumos y/o dispositivos médicos a EPS</span><input v-model="form.sells_medicines_eps" type="checkbox" class="accent-primary" /></div>
            <div class="s-row"><span>Tarifa ICA</span><input v-model="form.ica_rate" class="s-input w-[390px]" /></div>
            <div class="s-row"><span>Maneja AIU</span><input v-model="form.handles_aiu" type="checkbox" class="accent-primary" /></div>
            <div class="s-row"><span>Utilizo dos impuestos cargos en la factura de venta, doc. de ingreso y factura de compra</span><input v-model="form.two_charge_taxes" type="checkbox" class="accent-primary" /></div>
            <div class="s-row"><span>Es agente retenedor del impuesto sobre las ventas IVA</span><input v-model="form.iva_withholding_agent" type="checkbox" class="accent-primary" /></div>
            <div class="s-row"><span>Maneja impuesto Ad-Valorem (para industrias de licores)</span><input v-model="form.ad_valorem" type="checkbox" class="accent-primary" /></div>
            <div class="s-row items-start"><span>Responsabilidades fiscales</span>
                <div class="flex w-[390px] flex-wrap gap-2 rounded border border-line p-2">
                    <button v-for="(n, k) in catalogs.fiscal_responsibilities" :key="k" type="button" class="rounded-[3px] px-2 py-[2px] text-[12px]"
                        :class="form.fiscal_responsibilities.includes(k) ? 'bg-primary text-white' : 'bg-[#eef1f4] text-ink-soft'" @click="toggle(form.fiscal_responsibilities, k)">{{ n }}</button>
                </div>
            </div>
            <div class="s-row items-start"><span>Tributos</span>
                <div class="flex w-[390px] flex-wrap gap-2 rounded border border-line p-2">
                    <button v-for="(n, k) in catalogs.tributes" :key="k" type="button" class="rounded-[3px] px-2 py-[2px] text-[12px]"
                        :class="form.tributes.includes(k) ? 'bg-primary text-white' : 'bg-[#eef1f4] text-ink-soft'" @click="toggle(form.tributes, k)">{{ n }}</button>
                </div>
            </div>
        </div>

        <!-- Moneda extranjera -->
        <button type="button" class="s-form-section" @click="open.moneda = !open.moneda">
            <Icon :icon="open.moneda ? 'chevron-down' : 'chevron-right'" class="text-[10px]" /> Moneda extranjera
        </button>
        <div v-show="open.moneda" class="s-form-rows">
            <div class="s-row"><span>Maneja moneda extranjera</span><input v-model="form.foreign_currency" type="checkbox" class="accent-primary" /></div>
        </div>

        <!-- Representante legal -->
        <button type="button" class="s-form-section" @click="open.representante = !open.representante">
            <Icon :icon="open.representante ? 'chevron-down' : 'chevron-right'" class="text-[10px]" /> Datos del representante legal
        </button>
        <div v-show="open.representante" class="s-form-rows">
            <div class="s-row"><span>Nombres</span><input v-model="form.legal_rep_first_name" class="s-input w-[390px]" /></div>
            <div class="s-row"><span>Apellidos</span><input v-model="form.legal_rep_last_name" class="s-input w-[390px]" /></div>
            <div class="s-row"><span>Tipo Identificación</span>
                <select v-model="form.legal_rep_identification_type" class="s-input w-[390px]">
                    <option v-for="(n, k) in catalogs.identification_types" :key="k" :value="k">{{ n }}</option>
                </select>
            </div>
            <div class="s-row"><span>Identificación</span><input v-model="form.legal_rep_identification" class="s-input w-[180px]" /></div>
            <div class="s-row"><span>¿Tienes socios en la empresa?</span>
                <div class="flex gap-6">
                    <label class="flex items-center gap-2"><input v-model="form.has_partners" type="radio" :value="true" class="accent-primary" /> Sí</label>
                    <label class="flex items-center gap-2"><input v-model="form.has_partners" type="radio" :value="false" class="accent-primary" /> No</label>
                </div>
            </div>
        </div>
    </form>

    <div class="mt-3 flex">
        <button type="button" class="bg-[#7ac142] px-3 py-[7px] text-[13px] font-semibold text-white" :disabled="form.processing" @click="submit">Guardar</button>
        <Link href="/configuracion" class="bg-primary px-3 py-[7px] text-[13px] font-semibold text-white">Volver</Link>
    </div>

    <transition enter-from-class="opacity-0 translate-y-2" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
        <div v-if="warning" class="fixed bottom-6 right-6 z-[60] flex max-w-[420px] items-start gap-3 rounded-[8px] bg-[#FF4500] px-4 py-3 text-white shadow-lg" role="alert" aria-live="assertive">
            <Icon icon="triangle-exclamation" class="mt-[2px]" />
            <span>No se pudieron guardar los datos. Revisa los campos marcados en rojo.</span>
            <button type="button" class="ml-2 opacity-80 hover:opacity-100" aria-label="Cerrar notificación" @click="warning = false; clearTimeout(warningTimer)"><Icon icon="xmark" /></button>
        </div>
    </transition>
</template>

<style scoped>
.s-form-section { display: flex; width: 100%; align-items: center; gap: 10px; border-top: 1px solid #e6e9ec; background: #f5f6f7; padding: 7px 14px; font-size: 13px; color: #36393d; }
.s-form-section:first-child { border-top: 0; }
.s-form-rows { padding: 8px 0 14px; }
.s-row { display: flex; align-items: center; gap: 0; padding: 4px 14px; }
.s-row > span:first-child { width: 296px; flex-shrink: 0; padding-right: 14px; color: #36393d; }
</style>
