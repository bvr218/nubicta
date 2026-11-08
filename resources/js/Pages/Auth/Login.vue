<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '', remember: true });
const showPassword = ref(false);
const loginPhrase = import.meta.env.VITE_LOGIN_PHRASE || 'Gratis, de código abierto y con tus datos siempre bajo tu control.';

function submit() {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Inicio de sesión" />
    <div class="flex min-h-screen">
        <div class="hidden w-1/2 items-center justify-center bg-primary lg:flex">
            <div class="max-w-[420px] px-10 text-white">
                <img src="/brand/nubicta-logo-blanco.svg" alt="Nubicta" class="mb-10 w-[300px]" />
                <p class="text-[40px] font-extrabold leading-tight">Tu facturación electrónica, contabilidad y nómina</p>
                <p class="mt-4 text-[16px] opacity-90">{{ loginPhrase }}</p>
            </div>
        </div>
        <div class="flex w-full flex-col items-center justify-center bg-[#f3f6f9] lg:w-1/2">
            <img src="/brand/nubicta-logo.svg" alt="Nubicta" class="mb-6 w-[240px] lg:hidden" />
            <form class="s-card w-[390px] max-w-[calc(100vw-24px)] px-[30px] py-[28px] shadow-[0_2px_10px_rgba(0,0,0,.08)]" @submit.prevent="submit">
                <h1 class="text-[22px] font-bold text-[#34495e]">Inicio de sesión</h1>
                <p class="mb-[22px] mt-1 text-[13px] text-[#34495e]">Ingresa con tu usuario de la empresa</p>
                <p v-if="$page.props.flash?.success" class="mb-3 rounded-[2px] bg-[#e6f4dc] px-3 py-2 text-[12px] text-[#2e7d32]">{{ $page.props.flash.success }}</p>

                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    placeholder="Correo electrónico*"
                    class="s-input h-[34px]"
                    :class="{ 'is-invalid': form.errors.email }"
                    autofocus
                />
                <p v-if="form.errors.email" class="s-error">{{ form.errors.email }}</p>

                <div class="relative mt-[12px]">
                    <input
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        placeholder="Contraseña*"
                        class="s-input h-[34px] pr-9"
                        :class="{ 'is-invalid': form.errors.password }"
                    />
                    <button type="button" class="absolute right-3 top-[9px] text-primary" @click="showPassword = !showPassword">
                        <Icon :icon="showPassword ? 'eye-slash' : 'eye'" />
                    </button>
                </div>
                <p v-if="form.errors.password" class="s-error">{{ form.errors.password }}</p>

                <div class="mt-[6px] text-right">
                    <Link href="/recuperar-contrasena" class="text-[12px] font-semibold text-primary">¿Olvidaste tu contraseña?</Link>
                </div>

                <button type="submit" class="s-btn s-btn-primary mt-[16px] w-full font-bold" :disabled="form.processing">Continuar</button>
            </form>
        </div>
    </div>
</template>
