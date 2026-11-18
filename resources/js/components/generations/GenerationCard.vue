<script setup lang="ts">
import { Ban, CircleX, ExternalLink, ImageOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { Spinner } from '@/components/ui/spinner';
import { formatEstimate, formatUsd } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const imageFailedToLoad = ref(false);

const sizeLabels: Record<string, string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

const waitingText: Record<string, string> = {
    pending: 'Sending to Pixelforge…',
    queued: "Waiting in Pixelforge's queue…",
    processing: 'Generating…',
};

const showImage = computed(
    () =>
        props.generation.status === 'succeeded' &&
        props.generation.image_url !== null &&
        !imageFailedToLoad.value,
);

const imageUnavailable = computed(
    () => props.generation.status === 'succeeded' && !showImage.value,
);

/** What the user was charged, and why. */
const charge = computed(() => {
    const { status, actual_cost: actualCost } = props.generation;

    switch (status) {
        case 'succeeded':
            return {
                value: formatUsd(actualCost ?? '0'),
                note: 'exact final cost',
            };
        case 'failed':
            return {
                value: '$0.00',
                note: 'failed generations are free',
            };
        case 'rejected':
            return { value: '$0.00', note: 'nothing was created' };
        default:
            return { value: '—', note: 'billed once finished' };
    }
});

const dateFormat = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const createdAt = computed(() =>
    dateFormat.format(new Date(props.generation.created_at)),
);

const duration = computed(() => {
    const { created_at: created, completed_at: completed } = props.generation;

    if (!completed) {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.round((Date.parse(completed) - Date.parse(created)) / 1000),
    );

    return seconds < 120 ? `${seconds}s` : `${Math.round(seconds / 60)} min`;
});
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border bg-card shadow-xs"
    >
        <div
            class="relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-muted"
        >
            <a
                v-if="showImage && generation.image_url"
                :href="generation.image_url"
                target="_blank"
                rel="noopener noreferrer"
                class="group block size-full"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="size-full object-contain"
                    @error="imageFailedToLoad = true"
                />
                <ExternalLink
                    class="absolute top-2 right-2 size-7 rounded-md bg-background/80 p-1.5 opacity-0 transition-opacity group-hover:opacity-100"
                />
            </a>

            <div
                v-else-if="imageUnavailable"
                class="flex flex-col items-center gap-2 px-6 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-8" />
                <span class="font-medium text-foreground">
                    {{
                        generation.image_expired
                            ? 'Image link expired'
                            : 'Image unavailable'
                    }}
                </span>
                <span class="text-xs">
                    Pixelforge's image links stop working 24 hours after the
                    image is ready. The cost above is unaffected.
                </span>
            </div>

            <div
                v-else-if="!generation.is_final"
                class="flex size-full animate-pulse flex-col items-center justify-center gap-2 text-sm text-muted-foreground"
            >
                <Spinner class="size-6" />
                {{ waitingText[generation.status] }}
            </div>

            <div
                v-else
                class="flex flex-col items-center gap-2 px-6 text-center text-sm text-muted-foreground"
            >
                <CircleX v-if="generation.status === 'failed'" class="size-8" />
                <Ban v-else class="size-8" />
                <span class="font-medium text-foreground">
                    {{
                        generation.status === 'failed'
                            ? 'Generation failed'
                            : 'Not accepted by Pixelforge'
                    }}
                </span>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :status="generation.status" />
                <time
                    :datetime="generation.created_at"
                    class="text-xs text-muted-foreground"
                >
                    {{ createdAt }}
                    <template v-if="duration"> · took {{ duration }}</template>
                </time>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ sizeLabels[generation.size] ?? generation.size }} ·
                {{ generation.size.replace('x', ' × ') }} ·
                <span class="capitalize">{{ generation.quality }}</span>
                <template v-if="generation.reference_images.length">
                    · {{ generation.reference_images.length }} reference{{
                        generation.reference_images.length === 1 ? '' : 's'
                    }}
                </template>
            </p>

            <p
                v-if="generation.status_note"
                class="rounded-md bg-amber-50 px-2 py-1.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                {{ generation.status_note }}
            </p>

            <p
                v-if="generation.error_message"
                class="rounded-md bg-red-50 px-2 py-1.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200"
            >
                {{ generation.error_message }}
            </p>

            <dl class="mt-auto grid grid-cols-2 gap-2 border-t pt-3 text-sm">
                <div>
                    <dt class="text-xs text-muted-foreground">Estimated</dt>
                    <dd
                        class="tabular-nums"
                        :title="`Up to ${formatUsd(generation.max_cost)}`"
                    >
                        {{ formatEstimate(generation.estimated_cost) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Charged</dt>
                    <dd class="font-medium tabular-nums">
                        {{ charge.value }}
                        <span
                            class="block text-xs font-normal text-muted-foreground"
                        >
                            {{ charge.note }}
                        </span>
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
