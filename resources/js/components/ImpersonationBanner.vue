<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { UserRoundCog } from '@lucide/vue';
import { computed } from 'vue';
import { buttonVariants } from '@/components/ui/button';
import { destroy } from '@/routes/impersonation';

const page = usePage();

const impersonation = computed(() => page.props.impersonation);

const endsAt = computed(() =>
    impersonation.value
        ? new Date(impersonation.value.expiresAt).toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
          })
        : null,
);
</script>

<template>
    <div
        v-if="impersonation"
        role="status"
        class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        data-test="impersonation-banner"
    >
        <p class="flex items-center gap-2">
            <UserRoundCog class="size-4 shrink-0" />
            <span>
                Acting as <strong>{{ impersonation.user }}</strong> for
                {{ impersonation.operator }}. Ends at {{ endsAt }}.
            </span>
        </p>
        <Link
            :href="destroy()"
            as="button"
            :class="buttonVariants({ variant: 'outline', size: 'sm' })"
            data-test="end-impersonation"
        >
            End
        </Link>
    </div>
</template>
