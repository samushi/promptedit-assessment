<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Sparkles, TriangleAlert, X } from '@lucide/vue';
import { computed } from 'vue';
import CostEstimatePanel from '@/components/generations/CostEstimatePanel.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useCostEstimate } from '@/composables/useCostEstimate';
import { compareUsd, formatUsd } from '@/lib/money';
import { cn } from '@/lib/utils';
import { store } from '@/routes/generations';
import type { GenerationOptions } from '@/types';

const props = defineProps<{
    options: GenerationOptions;
    pricingAvailable: boolean;
    /** Spendable credits (USD, exact decimal string). */
    balance: string;
}>();

const draft = useForm({
    // One id per submission: sending the same form twice creates one generation.
    client_request_id: crypto.randomUUID(),
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const errors = computed(
    () => draft.errors as Record<string, string | undefined>,
);

/** Unicode characters (code points), counted like the server and Pixelforge. */
const characters = computed(() => [...draft.prompt.trim()].length);
const tooLong = computed(
    () => characters.value > props.options.maxPromptCharacters,
);

const { estimate, state: estimateState } = useCostEstimate(() => ({
    prompt: draft.prompt,
    size: draft.size,
    quality: draft.quality,
    reference_images: draft.reference_images,
}));

/** We reserve the most a generation can cost, so the balance must cover it. */
const cannotAfford = computed(
    () =>
        estimate.value !== null &&
        compareUsd(estimate.value.customer.max, props.balance) > 0,
);

const canSubmit = computed(
    () =>
        props.pricingAvailable &&
        estimateState.value !== 'unavailable' &&
        characters.value > 0 &&
        !tooLong.value &&
        !cannotAfford.value &&
        !draft.processing,
);

// Bound from script: vue-tsc 3.3 mis-handles "//" inside static attributes.
const referencePlaceholder = 'https://example.com/style.png';

const sizeHints: Record<string, string> = {
    '1024x1024': '1024 × 1024',
    '1536x1024': '1536 × 1024',
    '1024x1536': '1024 × 1536',
};

function addReference(): void {
    if (draft.reference_images.length < props.options.maxReferenceImages) {
        draft.reference_images.push('');
    }
}

function removeReference(index: number): void {
    draft.reference_images.splice(index, 1);
    draft.clearErrors();
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    // Drop empty rows so error messages line up with the fields shown.
    draft.reference_images = draft.reference_images
        .map((url) => url.trim())
        .filter((url) => url !== '');

    draft.post(store.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // Keep the prompt for tweaking; the next click is a new request.
            draft.client_request_id = crypto.randomUUID();
        },
    });
}
</script>

<template>
    <form
        class="grid gap-6 rounded-xl border bg-card p-4 shadow-xs md:p-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
        @submit.prevent="submit"
    >
        <div class="space-y-5">
            <div class="grid gap-2">
                <div class="flex items-baseline justify-between">
                    <Label for="prompt">Prompt</Label>
                    <span
                        class="text-xs tabular-nums"
                        :class="
                            tooLong
                                ? 'font-medium text-red-600'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ characters.toLocaleString('en-US') }} /
                        {{
                            options.maxPromptCharacters.toLocaleString('en-US')
                        }}
                    </span>
                </div>
                <textarea
                    id="prompt"
                    v-model="draft.prompt"
                    rows="4"
                    required
                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                    :aria-invalid="tooLong || !!errors.prompt"
                    class="w-full min-w-0 resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                />
                <InputError :message="errors.prompt" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Size</legend>
                <div class="grid grid-cols-3 gap-2">
                    <label
                        v-for="size in options.sizes"
                        :key="size.id"
                        :class="
                            cn(
                                'flex cursor-pointer flex-col items-center rounded-lg border px-2 py-2 text-center text-sm transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                draft.size === size.id
                                    ? 'border-primary bg-primary/5 font-medium'
                                    : 'hover:bg-accent',
                            )
                        "
                    >
                        <input
                            v-model="draft.size"
                            type="radio"
                            name="size"
                            :value="size.id"
                            class="sr-only"
                        />
                        {{ size.name }}
                        <span class="text-xs text-muted-foreground">{{
                            sizeHints[size.id]
                        }}</span>
                    </label>
                </div>
                <InputError :message="errors.size" />
            </fieldset>

            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Quality</legend>
                <div class="grid grid-cols-3 gap-2">
                    <label
                        v-for="quality in options.qualities"
                        :key="quality.id"
                        :class="
                            cn(
                                'cursor-pointer rounded-lg border px-2 py-2 text-center text-sm transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                draft.quality === quality.id
                                    ? 'border-primary bg-primary/5 font-medium'
                                    : 'hover:bg-accent',
                            )
                        "
                    >
                        <input
                            v-model="draft.quality"
                            type="radio"
                            name="quality"
                            :value="quality.id"
                            class="sr-only"
                        />
                        {{ quality.name }}
                    </label>
                </div>
                <InputError :message="errors.quality" />
            </fieldset>

            <div class="grid gap-2">
                <div class="flex items-baseline justify-between">
                    <span class="text-sm font-medium">
                        Reference images
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        >
                    </span>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ draft.reference_images.length }} /
                        {{ options.maxReferenceImages }}
                    </span>
                </div>
                <p class="text-xs text-muted-foreground">
                    Public http(s) image URLs that guide the style. Each one
                    adds 765 input image tokens to the price.
                </p>

                <div
                    v-for="(reference, index) in draft.reference_images"
                    :key="index"
                    class="grid gap-1"
                >
                    <div class="flex gap-2">
                        <Input
                            v-model="draft.reference_images[index]"
                            type="url"
                            inputmode="url"
                            :aria-label="`Reference image ${index + 1}`"
                            :aria-invalid="
                                !!errors[`reference_images.${index}`]
                            "
                            :placeholder="referencePlaceholder"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Remove reference image ${index + 1}`"
                            @click="removeReference(index)"
                        >
                            <X />
                        </Button>
                    </div>
                    <InputError
                        :message="errors[`reference_images.${index}`]"
                    />
                </div>

                <InputError :message="errors.reference_images" />

                <div>
                    <Button
                        v-if="
                            draft.reference_images.length <
                            options.maxReferenceImages
                        "
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addReference"
                    >
                        <Plus />
                        Add reference image
                    </Button>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-4">
            <CostEstimatePanel :estimate="estimate" :state="estimateState" />

            <p
                v-if="!pricingAvailable && estimateState !== 'unavailable'"
                class="flex gap-2 text-sm text-amber-700 dark:text-amber-400"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                Pricing is temporarily unavailable. Please try again shortly.
            </p>
            <p
                v-if="cannotAfford && estimate"
                class="flex gap-2 text-sm text-amber-700 dark:text-amber-400"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                This could cost up to
                {{ formatUsd(estimate.customer.max) }} and your balance is
                {{ formatUsd(balance) }}. Buy credits to continue.
            </p>
            <InputError :message="errors.pricing" />
            <InputError :message="errors.limit" />
            <InputError :message="errors.credits" />

            <Button type="submit" size="lg" :disabled="!canSubmit">
                <Spinner v-if="draft.processing" />
                <Sparkles v-else />
                {{ draft.processing ? 'Queueing…' : 'Generate image' }}
            </Button>
        </div>
    </form>
</template>
