<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const page = usePage();
</script>

<template>
    <Head title="Welcome" />

    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <main
            class="flex w-full max-w-sm flex-col items-center gap-8 text-center"
        >
            <div class="flex flex-col items-center gap-4">
                <AppLogoIcon
                    class="size-9 fill-current text-[var(--foreground)] dark:text-white"
                />
                <div class="space-y-2">
                    <h1 class="text-xl font-medium">{{ page.props.name }}</h1>
                    <p class="text-sm text-muted-foreground">
                        Sign in to your organization, or create an account to
                        start one.
                    </p>
                </div>
            </div>

            <div v-if="page.props.auth.user" class="flex w-full flex-col gap-3">
                <Button as-child class="w-full">
                    <Link :href="dashboard()">Go to dashboard</Link>
                </Button>
            </div>

            <div v-else class="flex w-full flex-col gap-3">
                <Button as-child class="w-full">
                    <Link :href="login()">Log in</Link>
                </Button>
                <Button as-child variant="outline" class="w-full">
                    <Link :href="register()">Register</Link>
                </Button>
            </div>
        </main>
    </div>
</template>
