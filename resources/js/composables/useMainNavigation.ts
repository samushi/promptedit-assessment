import { usePage } from '@inertiajs/vue3';
import { ChartColumn, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { report } from '@/routes/admin';
import { index as generations } from '@/routes/generations';
import type { NavItem } from '@/types';

/** Main navigation; the admin report only appears for operators. */
export function useMainNavigation(): ComputedRef<NavItem[]> {
    const page = usePage();

    return computed(() => [
        { title: 'Generate', href: generations(), icon: Sparkles },
        ...(page.props.auth.user?.is_admin
            ? [{ title: 'Admin report', href: report(), icon: ChartColumn }]
            : []),
    ]);
}
