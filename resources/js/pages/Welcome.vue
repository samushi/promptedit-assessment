<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, Receipt, Sparkles } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { index as generations } from '@/routes/generations';

const features = [
    {
        icon: Sparkles,
        title: 'Know the price first',
        body: 'Every request shows an estimated price, with its range, before you submit.',
    },
    {
        icon: Receipt,
        title: 'Pay the exact cost',
        body: 'You pay exactly what the image cost to make plus 25%: never twice, and nothing if it fails.',
    },
    {
        icon: Activity,
        title: 'Watch it happen',
        body: 'Queued, processing, done: every image updates live, with a clear reason when something goes wrong.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between p-6"
        >
            <div class="flex items-center gap-2 font-semibold">
                <span
                    class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                {{ $page.props.name }}
            </div>

            <nav class="flex items-center gap-2">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="generations()">Open the studio</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="register()">Get started</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main
            class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-12 px-6 pb-16"
        >
            <div class="max-w-2xl space-y-5">
                <h1
                    class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    Generate images. Pay exactly what they cost.
                </h1>
                <p class="text-lg text-muted-foreground">
                    Describe an image, pick a size and quality, and see what it
                    will cost before you submit. Powered by Pixelforge.
                </p>
                <Button size="lg" as-child>
                    <Link
                        :href="
                            $page.props.auth.user ? generations() : register()
                        "
                    >
                        <Sparkles />
                        Start generating
                    </Link>
                </Button>
            </div>

            <ul class="grid gap-4 sm:grid-cols-3">
                <li
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border bg-card p-5"
                >
                    <component
                        :is="feature.icon"
                        class="mb-3 size-5 text-muted-foreground"
                    />
                    <h2 class="font-medium">{{ feature.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ feature.body }}
                    </p>
                </li>
            </ul>
        </main>
    </div>
</template>
