<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleAlert } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';
import { index as billing } from '@/routes/organizations/billing';
import type { ProjectAllowance } from '@/types';

// No threshold of its own: every value comes from the server, so the prompt
// cannot disagree with what the endpoint refuses.
defineProps<{
    allowance: ProjectAllowance;
}>();
</script>

<template>
    <Alert variant="destructive">
        <CircleAlert class="size-4" />
        <AlertTitle>
            {{
                allowance.limit === 0
                    ? 'This plan includes no projects'
                    : 'You have used every project on this plan'
            }}
        </AlertTitle>
        <AlertDescription class="flex flex-col items-start gap-3">
            <p>
                <template v-if="allowance.limit === 0">
                    This organization cannot create projects until it moves to a
                    plan that includes them.
                </template>
                <template v-else>
                    {{ allowance.usage }} of {{ allowance.limit }} projects are
                    in use. Existing projects stay exactly as they are.
                </template>
                <template v-if="allowance.upgradePlan">
                    Upgrading to {{ allowance.upgradePlan }} allows more.
                </template>
            </p>

            <Link
                :href="billing()"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
            >
                {{
                    allowance.upgradePlan
                        ? `Upgrade to ${allowance.upgradePlan}`
                        : 'View billing'
                }}
            </Link>
        </AlertDescription>
    </Alert>
</template>
