<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, ImagePlus } from '@lucide/vue';
import { useDocumentVisibility, useNow } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import CreditsBar from '@/components/generations/CreditsBar.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/generations';
import type {
    Credits,
    Generation,
    GenerationOptions,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: index() }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    credits: Credits;
    options: GenerationOptions;
    pricingAvailable: boolean;
}>();

const hasActive = computed(() =>
    props.generations.data.some((generation) => !generation.is_final),
);

// Live status: while anything is in flight, reload just the history and the
// balance every 3 seconds (form state and scroll position are kept). Bounded,
// so a forgotten tab can't keep a scale-to-zero server awake: it pauses while
// the tab is hidden, and after 15 minutes without any change until asked.
const LIVE_WINDOW_MS = 15 * 60 * 1000;

const visibility = useDocumentVisibility();
const now = useNow({ interval: 30_000 });
const lastActivityAt = ref(Date.now());

const progress = computed(() =>
    props.generations.data
        .filter((generation) => !generation.is_final)
        .map((g) => `${g.id}:${g.status}:${g.status_note ?? ''}`)
        .join('|'),
);

watch(progress, () => (lastActivityAt.value = Date.now()));
watch(visibility, (state) => {
    if (state === 'visible') {
        lastActivityAt.value = Date.now();
    }
});

const paused = computed(
    () =>
        hasActive.value &&
        now.value.getTime() - lastActivityAt.value > LIVE_WINDOW_MS,
);
const live = computed(
    () => hasActive.value && !paused.value && visibility.value === 'visible',
);

const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'credits'] },
    { autoStart: false },
);

watch(live, (on) => (on ? start() : stop()), { immediate: true });

function checkAgain(): void {
    lastActivityAt.value = Date.now();
    router.reload({ only: ['generations', 'credits'] });
}
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <section class="space-y-4" aria-labelledby="generate-heading">
            <div>
                <h1
                    id="generate-heading"
                    class="text-xl font-semibold tracking-tight"
                >
                    Generate an image
                </h1>
                <p class="text-sm text-muted-foreground">
                    See the estimated price before you submit. You're charged
                    the exact cost once it's done, and nothing if it fails.
                </p>
            </div>

            <CreditsBar :credits="credits" />

            <GenerationForm
                :options="options"
                :pricing-available="pricingAvailable"
                :balance="credits.balance"
            />
        </section>

        <section class="space-y-4" aria-labelledby="history-heading">
            <div class="flex items-baseline justify-between gap-4">
                <h2 id="history-heading" class="text-lg font-semibold">
                    Your generations
                </h2>
                <p
                    v-if="live"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                    aria-live="polite"
                >
                    <Spinner class="size-3" />
                    Updating live
                </p>
                <p
                    v-else-if="paused"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                    aria-live="polite"
                >
                    Live updates paused: nothing changed for 15 minutes.
                    <button
                        type="button"
                        class="font-medium text-foreground underline underline-offset-4"
                        @click="checkAgain"
                    >
                        Check again
                    </button>
                </p>
            </div>

            <div
                v-if="generations.data.length === 0"
                class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                <ImagePlus class="size-8" />
                <p class="font-medium text-foreground">No generations yet</p>
                <p>Your images, their status and what they cost appear here.</p>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>

            <nav
                v-if="generations.meta.last_page > 1"
                class="flex items-center justify-between text-sm"
                aria-label="History pages"
            >
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    preserve-scroll
                    class="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 hover:bg-accent"
                >
                    <ChevronLeft class="size-4" /> Newer
                </Link>
                <span v-else />
                <span class="text-muted-foreground">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    preserve-scroll
                    class="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 hover:bg-accent"
                >
                    Older <ChevronRight class="size-4" />
                </Link>
                <span v-else />
            </nav>
        </section>
    </div>
</template>
