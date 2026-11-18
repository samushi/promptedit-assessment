<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronRight, CircleCheck, TriangleAlert } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsd } from '@/lib/money';
import { report as reportRoute } from '@/routes/admin';
import type { AdminReport, PixelforgeComparison } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: reportRoute() }],
    },
});

const props = defineProps<{
    report: AdminReport;
    maxDays: number;
}>();

const range = reactive({ start: props.report.start, end: props.report.end });
const expanded = ref(new Set<string>());
const showEmptyDays = ref(false);

const activeDays = computed(() =>
    props.report.days.filter(
        (day) =>
            showEmptyDays.value ||
            day.generations > 0 ||
            (day.pixelforge !== null && day.pixelforge.generations > 0),
    ),
);

const hiddenDays = computed(
    () => props.report.days.length - activeDays.value.length,
);

function applyRange(): void {
    router.get(reportRoute.url(), { ...range }, { preserveScroll: true });
}

function toggle(date: string): void {
    if (expanded.value.has(date)) {
        expanded.value.delete(date);
    } else {
        expanded.value.add(date);
    }
}

function differenceLabel(comparison: PixelforgeComparison): string {
    if (comparison === null) {
        return 'n/a';
    }

    return comparison.matches
        ? 'Exact match'
        : formatUsd(comparison.difference);
}
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Admin report
                </h1>
                <p class="max-w-2xl text-sm text-muted-foreground">
                    What users were charged, what Pixelforge charged us, and the
                    profit. Dates are UTC; a generation counts on the day
                    Pixelforge completed it, as in Pixelforge's own usage report
                    (GET /v1/usage) for this API key.
                </p>
            </div>

            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="applyRange"
            >
                <div class="grid gap-1">
                    <Label for="start" class="text-xs">From (UTC)</Label>
                    <Input
                        id="start"
                        v-model="range.start"
                        type="date"
                        class="w-40"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="end" class="text-xs">To (UTC)</Label>
                    <Input
                        id="end"
                        v-model="range.end"
                        type="date"
                        class="w-40"
                    />
                </div>
                <Button type="submit" variant="outline">Show</Button>
            </form>
        </div>

        <p
            v-if="report.pixelforgeError"
            class="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            {{ report.pixelforgeError }}
        </p>

        <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border bg-card p-4">
                <dt class="text-sm text-muted-foreground">Charged to users</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ formatUsd(report.totals.charged) }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    {{ report.totals.generations }} billed generations
                </dd>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <dt class="text-sm text-muted-foreground">
                    Pixelforge cost (our records)
                </dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ formatUsd(report.totals.provider_cost) }}
                </dd>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <dt class="text-sm text-muted-foreground">
                    Pixelforge cost (its usage report)
                </dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{
                        report.totals.pixelforge
                            ? formatUsd(report.totals.pixelforge.cost)
                            : 'n/a'
                    }}
                </dd>
                <dd
                    v-if="report.totals.pixelforge"
                    class="flex items-center gap-1 text-xs"
                    :class="
                        report.totals.pixelforge.matches
                            ? 'text-emerald-700 dark:text-emerald-400'
                            : 'text-red-700 dark:text-red-400'
                    "
                >
                    <CircleCheck
                        v-if="report.totals.pixelforge.matches"
                        class="size-3.5"
                    />
                    <TriangleAlert v-else class="size-3.5" />
                    {{
                        report.totals.pixelforge.matches
                            ? 'Matches our records exactly'
                            : `Differs by ${formatUsd(report.totals.pixelforge.difference)}`
                    }}
                </dd>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <dt class="text-sm text-muted-foreground">Profit</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ formatUsd(report.totals.profit) }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    25% markup on Pixelforge's cost
                </dd>
            </div>
        </dl>

        <section class="space-y-2" aria-labelledby="by-day">
            <div class="flex items-baseline justify-between">
                <h2 id="by-day" class="font-semibold">By day</h2>
                <button
                    v-if="hiddenDays > 0 || showEmptyDays"
                    type="button"
                    class="text-xs text-muted-foreground underline-offset-4 hover:underline"
                    @click="showEmptyDays = !showEmptyDays"
                >
                    {{
                        showEmptyDays
                            ? 'Hide days without usage'
                            : `Show ${hiddenDays} days without usage`
                    }}
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm tabular-nums">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Date (UTC)</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Charged
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge (ours)
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge (reported)
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Difference
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="day in activeDays" :key="day.date">
                            <tr class="border-t">
                                <td class="px-3 py-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 font-medium"
                                        :aria-expanded="expanded.has(day.date)"
                                        :disabled="day.users.length === 0"
                                        @click="toggle(day.date)"
                                    >
                                        <ChevronRight
                                            class="size-4 transition-transform"
                                            :class="[
                                                expanded.has(day.date)
                                                    ? 'rotate-90'
                                                    : '',
                                                day.users.length === 0
                                                    ? 'opacity-0'
                                                    : '',
                                            ]"
                                        />
                                        {{ day.date }}
                                    </button>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ day.generations }}
                                    <span
                                        v-if="
                                            day.pixelforge &&
                                            day.pixelforge.generations !==
                                                day.generations
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        (Pixelforge:
                                        {{ day.pixelforge.generations }})
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ formatUsd(day.charged) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ formatUsd(day.provider_cost) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{
                                        day.pixelforge
                                            ? formatUsd(day.pixelforge.cost)
                                            : 'n/a'
                                    }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right"
                                    :class="
                                        day.pixelforge?.matches
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : day.pixelforge
                                              ? 'font-medium text-red-700 dark:text-red-400'
                                              : 'text-muted-foreground'
                                    "
                                >
                                    {{ differenceLabel(day.pixelforge) }}
                                    <span
                                        v-if="
                                            day.pixelforge &&
                                            !day.pixelforge.tokens_match
                                        "
                                        class="block text-xs text-amber-700 dark:text-amber-400"
                                    >
                                        token totals differ
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ formatUsd(day.profit) }}
                                </td>
                            </tr>
                            <template v-if="expanded.has(day.date)">
                                <tr
                                    v-for="row in day.users"
                                    :key="`${day.date}-${row.user.id ?? 'deleted'}`"
                                    class="bg-muted/30 text-xs"
                                >
                                    <td class="py-1.5 pr-3 pl-9">
                                        {{ row.user.name }}
                                        <span class="text-muted-foreground">{{
                                            row.user.email
                                        }}</span>
                                    </td>
                                    <td class="px-3 py-1.5 text-right">
                                        {{ row.generations }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right">
                                        {{ formatUsd(row.charged) }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right">
                                        {{ formatUsd(row.provider_cost) }}
                                    </td>
                                    <td class="px-3 py-1.5" colspan="2" />
                                    <td class="px-3 py-1.5 text-right">
                                        {{ formatUsd(row.profit) }}
                                    </td>
                                </tr>
                            </template>
                        </template>
                        <tr v-if="activeDays.length === 0" class="border-t">
                            <td
                                colspan="7"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No billed generations in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-muted-foreground">
                A difference on today's date can be temporary while generations
                finish or webhooks are retried. Up to
                {{ maxDays }} days per report.
            </p>
        </section>

        <section class="space-y-2" aria-labelledby="by-user">
            <h2 id="by-user" class="font-semibold">By user</h2>
            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm tabular-nums">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">User</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Charged
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in report.users"
                            :key="row.user.id ?? 'deleted'"
                            class="border-t"
                        >
                            <td class="px-3 py-2">
                                {{ row.user.name }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ row.user.email }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ row.generations }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(row.charged) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(row.provider_cost) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ formatUsd(row.profit) }}
                            </td>
                        </tr>
                        <tr v-if="report.users.length === 0" class="border-t">
                            <td
                                colspan="5"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No billed generations in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
