<script setup lang="ts">
import { Ban, CircleCheck, CircleX, Clock, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
}>();

const appearance = {
    pending: {
        label: 'Submitting',
        icon: LoaderCircle,
        spin: true,
        class: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    },
    queued: {
        label: 'Queued',
        icon: Clock,
        spin: false,
        class: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    },
    processing: {
        label: 'Processing',
        icon: LoaderCircle,
        spin: true,
        class: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    },
    succeeded: {
        label: 'Succeeded',
        icon: CircleCheck,
        spin: false,
        class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    },
    failed: {
        label: 'Failed',
        icon: CircleX,
        spin: false,
        class: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    },
    rejected: {
        label: 'Not accepted',
        icon: Ban,
        spin: false,
        class: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
    },
} as const;

const current = computed(() => appearance[props.status]);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                current.class,
            )
        "
    >
        <component
            :is="current.icon"
            :class="cn('size-3.5', current.spin && 'animate-spin')"
            aria-hidden="true"
        />
        {{ current.label }}
    </span>
</template>
