<script setup lang="ts">
import { ChevronRight, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import type { EstimateState } from '@/composables/useCostEstimate';
import { formatEstimate, formatUsd } from '@/lib/money';
import type { CostEstimate, EstimateLine } from '@/types';

defineProps<{
    estimate: CostEstimate | null;
    state: EstimateState;
}>();

const showBreakdown = ref(false);

function formatTokens(line: EstimateLine): string {
    const { min, expected, max } = line.tokens;
    const count = Number(expected).toLocaleString('en-US');

    return min === max
        ? count
        : `~${count} (${min.toLocaleString('en-US')}–${max.toLocaleString('en-US')})`;
}
</script>

<template>
    <div class="rounded-xl border bg-muted/40 p-4" aria-live="polite">
        <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-medium text-muted-foreground">
                Estimated price
            </span>
            <span
                class="rounded-full border px-2 py-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
            >
                Estimate
            </span>
        </div>

        <p
            v-if="state === 'unavailable'"
            class="mt-3 flex gap-2 text-sm text-amber-700 dark:text-amber-400"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            Pricing is temporarily unavailable, so we can't show what this will
            cost. Generating is paused until it's back.
        </p>

        <p
            v-else-if="state === 'invalid'"
            class="mt-3 text-sm text-muted-foreground"
        >
            Fix the highlighted fields to see a price.
        </p>

        <template v-else-if="estimate">
            <div
                class="transition-opacity"
                :class="state === 'loading' ? 'opacity-50' : ''"
            >
                <p class="mt-2 text-3xl font-semibold tabular-nums">
                    {{ formatEstimate(estimate.customer.expected) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Final price between
                    <span class="font-medium text-foreground tabular-nums">{{
                        formatUsd(estimate.customer.min, 4)
                    }}</span>
                    and
                    <span class="font-medium text-foreground tabular-nums">{{
                        formatUsd(estimate.customer.max, 4)
                    }}</span>
                </p>
            </div>

            <button
                type="button"
                class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-foreground/80 hover:text-foreground"
                :aria-expanded="showBreakdown"
                @click="showBreakdown = !showBreakdown"
            >
                <ChevronRight
                    class="size-4 transition-transform"
                    :class="showBreakdown ? 'rotate-90' : ''"
                />
                How is this calculated?
            </button>

            <div v-if="showBreakdown" class="mt-2 text-xs tabular-nums">
                <ul class="divide-y divide-border/60">
                    <li
                        v-for="line in estimate.lines"
                        :key="line.type"
                        class="py-1.5"
                    >
                        <div class="flex justify-between gap-2">
                            <span>{{ line.label }}</span>
                            <span>{{ formatUsd(line.expected_cost) }}</span>
                        </div>
                        <div class="text-muted-foreground">
                            {{ formatTokens(line) }} tokens ×
                            {{ formatUsd(line.rate_per_million) }} per 1M
                        </div>
                    </li>
                </ul>
                <dl class="mt-1 space-y-1 border-t border-border pt-2">
                    <div class="flex justify-between gap-2">
                        <dt>Pixelforge's cost</dt>
                        <dd>{{ formatUsd(estimate.provider.expected) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>+ 25% markup</dt>
                        <dd>{{ formatUsd(estimate.markup) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 font-medium">
                        <dt>Your estimated price</dt>
                        <dd>{{ formatUsd(estimate.customer.expected) }}</dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs text-muted-foreground">
                    Reasoning is assumed at the midpoint of its range for this
                    quality; prompt text at one token per 4 characters (±15%).
                </p>
            </div>

            <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                Some token counts are only known once the image is ready, so
                this is an estimate. You pay the exact final cost (Pixelforge's
                price plus 25%). Failed generations are free.
            </p>
        </template>

        <p
            v-else
            class="mt-3 flex items-center gap-2 text-sm text-muted-foreground"
        >
            <Spinner />
            Calculating…
        </p>
    </div>
</template>
