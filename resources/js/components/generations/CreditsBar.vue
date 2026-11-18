<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Coins } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { compareUsd, formatUsd } from '@/lib/money';
import { store as purchaseCredits } from '@/routes/credits';
import type { Credits } from '@/types';

const props = defineProps<{
    credits: Credits;
}>();

const buying = ref<string | null>(null);
const hasReservation = computed(
    () => compareUsd(props.credits.reserved, '0') > 0,
);

function buy(amount: string): void {
    buying.value = amount;

    router.post(
        purchaseCredits.url(),
        // A new id per click: a double submit can only add credits once.
        { amount, reference: crypto.randomUUID() },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (buying.value = null),
        },
    );
}
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card px-4 py-3 shadow-xs"
    >
        <div class="flex items-center gap-3">
            <Coins class="size-5 text-muted-foreground" />
            <div>
                <p class="text-xs text-muted-foreground">Credit balance</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ formatUsd(credits.balance) }}
                </p>
            </div>
            <p
                v-if="hasReservation"
                class="max-w-56 text-xs text-muted-foreground"
            >
                {{ formatUsd(credits.reserved) }} is reserved for images in
                progress; the unused part comes back when they finish.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-muted-foreground">
                Buy credits (test mode, no payment):
            </span>
            <Button
                v-for="amount in credits.packages"
                :key="amount"
                size="sm"
                variant="outline"
                :disabled="buying !== null"
                @click="buy(amount)"
            >
                <Spinner v-if="buying === amount" />
                + {{ formatUsd(amount) }}
            </Button>
        </div>
    </div>
</template>
