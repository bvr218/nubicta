<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    selectedId: { type: Number, default: null },
    expandAll: { type: Boolean, default: false },
});
const emit = defineEmits(['select']);

const open = ref(props.expandAll && !!props.node.children?.length);
const children = ref(props.node.children ?? null);
watch(() => props.expandAll, (v) => {
    if (props.node.children) children.value = props.node.children;
    open.value = v && !!children.value?.length;
});
const loading = ref(false);

async function load() {
    if (children.value) return;
    loading.value = true;
    const res = await fetch(`/contabilidad/cuentas/${props.node.id}/hijos`, { headers: { Accept: 'application/json' } });
    children.value = await res.json();
    loading.value = false;
}

async function toggle() {
    if (!props.node.has_children) return;
    open.value = !open.value;
    if (open.value) await load();
}

defineExpose({ toggle });
</script>

<template>
    <div>
        <div
            :data-account-id="node.id"
            class="grid cursor-pointer grid-cols-[136px_1fr] border-b border-[#f1f3f5] hover:bg-[#f8fbff]"
            :class="{ 'bg-primary-light': selectedId === node.id }"
            @click="emit('select', node)"
        >
            <div class="flex items-center gap-1 py-[7px] pr-2" :style="{ paddingLeft: `${8 + depth * 12}px` }">
                <button v-if="node.has_children" type="button" class="w-3 text-[9px] text-muted" @click.stop="toggle">
                    <Icon :icon="open ? 'chevron-down' : 'chevron-right'" />
                </button>
                <span v-else class="w-3" />
                <span :class="node.transactional ? 'text-ink-soft' : 'text-ink'">{{ node.code }}</span>
            </div>
            <div class="truncate border-l border-[#e6e9ec] py-[7px] pl-[8px]" :class="{ 'text-muted line-through': !node.active }">
                {{ node.name }}
            </div>
        </div>
        <div v-if="open">
            <p v-if="loading" class="py-1 pl-10 text-[12px] text-muted">Cargando...</p>
            <AccountNode
                v-for="c in children ?? []"
                :key="c.id"
                :node="c"
                :depth="depth + 1"
                :selected-id="selectedId"
                :expand-all="expandAll"
                @select="emit('select', $event)"
            />
        </div>
    </div>
</template>
