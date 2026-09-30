<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PaginationLink } from '@/types/pagination';

defineProps<{
    links: PaginationLink[];
    label: string;
}>();

// Laravel labels the ends with HTML entities. Stripping them beats v-html, which
// Inertia's Link swallows into its own slot and renders as an empty anchor.
function text(label: string): string {
    return label.replace(/&laquo;|&raquo;/g, '').trim();
}
</script>

<template>
    <nav
        v-if="links.length > 3"
        class="flex flex-wrap gap-1"
        :aria-label="label"
    >
        <Link
            v-for="(link, index) in links"
            :key="index"
            :href="link.url ?? '#'"
            :aria-current="link.active ? 'page' : undefined"
            :aria-disabled="link.url === null"
            class="inline-flex h-11 min-w-11 items-center justify-center rounded-md px-3 text-sm"
            :class="[
                link.active
                    ? 'bg-primary text-primary-foreground'
                    : 'hover:bg-muted',
                link.url === null && 'pointer-events-none opacity-50',
            ]"
        >
            {{ text(link.label) }}
        </Link>
    </nav>
</template>
