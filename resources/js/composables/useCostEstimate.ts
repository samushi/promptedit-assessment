import { useDebounceFn } from '@vueuse/core';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { estimate as estimateRoute } from '@/routes/generations';
import type { CostEstimate, GenerationInput } from '@/types';

export type EstimateState = 'loading' | 'ready' | 'invalid' | 'unavailable';

function xsrfToken(): string {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Live price for the request being typed. The browser never calculates a
 * price: it asks the server (the same code that quotes the real submit) and
 * only displays the exact figures it gets back.
 */
export function useCostEstimate(input: () => GenerationInput): {
    estimate: Ref<CostEstimate | null>;
    state: Ref<EstimateState>;
} {
    const estimate = ref<CostEstimate | null>(null);
    const state = ref<EstimateState>('loading');
    let inFlight: AbortController | null = null;

    async function refresh(): Promise<void> {
        inFlight?.abort();
        const request = new AbortController();
        inFlight = request;

        try {
            const response = await fetch(estimateRoute.url(), {
                method: 'POST',
                credentials: 'same-origin',
                signal: request.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify(input()),
            });
            const body: unknown = response.ok ? await response.json() : null;

            // A newer request was started meanwhile: its answer wins.
            if (request !== inFlight) {
                return;
            }

            if (response.ok) {
                estimate.value = (body as { estimate: CostEstimate }).estimate;
                state.value = 'ready';
            } else {
                estimate.value = null;
                state.value =
                    response.status === 422 ? 'invalid' : 'unavailable';
            }
        } catch (error) {
            const aborted =
                error instanceof DOMException && error.name === 'AbortError';

            if (!aborted && request === inFlight) {
                estimate.value = null;
                state.value = 'unavailable';
            }
        }
    }

    const scheduleRefresh = useDebounceFn(refresh, 250);

    watch(
        () => JSON.stringify(input()),
        () => {
            state.value = 'loading';
            void scheduleRefresh();
        },
        { immediate: true },
    );

    onBeforeUnmount(() => inFlight?.abort());

    return { estimate, state };
}
