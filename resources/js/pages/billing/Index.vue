<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CircleAlert, Clock3 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/organizations/billing';
import type {
    BillingPlan,
    BillingPrice,
    BillingState,
    CurrentSubscription,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: index() }],
    },
});

defineProps<{
    plans: BillingPlan[];
    subscription: CurrentSubscription | null;
    canManageBilling: boolean;
}>();

const stateLabels: Record<BillingState, string> = {
    active: 'Active',
    grace_period: 'Ending',
    past_due: 'Past due',
};

function formatAmount(price: BillingPrice): string {
    if (price.amount === 0) {
        return 'Free';
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: price.currency.toUpperCase(),
        maximumFractionDigits: 0,
    }).format(price.amount / 100);
}
</script>

<template>
    <div class="flex flex-col gap-8 p-4">
        <Head title="Billing" />

        <Heading
            variant="small"
            title="Billing"
            description="The plan and billing state for this organization"
        />

        <p v-if="!canManageBilling" class="text-sm text-muted-foreground">
            You can view billing details. An administrator manages changes.
        </p>

        <section v-if="subscription" class="flex flex-col gap-4">
            <h2 class="text-sm font-medium">Current subscription</h2>

            <Card>
                <CardHeader>
                    <div class="flex flex-wrap items-center gap-2">
                        <CardTitle>
                            {{ subscription.plan?.name ?? 'Unknown plan' }}
                        </CardTitle>
                        <Badge
                            :variant="
                                subscription.state === 'past_due'
                                    ? 'destructive'
                                    : 'secondary'
                            "
                        >
                            {{ stateLabels[subscription.state] }}
                        </Badge>
                    </div>
                    <CardDescription v-if="subscription.price">
                        {{ formatAmount(subscription.price) }} per
                        {{ subscription.price.interval }}
                    </CardDescription>
                </CardHeader>

                <CardContent class="flex flex-col gap-3">
                    <Alert
                        v-if="subscription.state === 'past_due'"
                        variant="destructive"
                    >
                        <CircleAlert />
                        <AlertTitle>Payment is past due</AlertTitle>
                        <AlertDescription>
                            An administrator should resolve the payment with the
                            billing provider.
                        </AlertDescription>
                    </Alert>

                    <Alert v-else-if="subscription.state === 'grace_period'">
                        <Clock3 />
                        <AlertTitle>Subscription ending</AlertTitle>
                        <AlertDescription>
                            Access continues through
                            {{ subscription.endsAt ?? 'the current period' }}.
                        </AlertDescription>
                    </Alert>

                    <p
                        v-else-if="subscription.trialEndsAt"
                        class="text-sm text-muted-foreground"
                    >
                        Trial ends {{ subscription.trialEndsAt }}.
                    </p>
                </CardContent>
            </Card>
        </section>

        <section class="flex flex-col gap-4">
            <div>
                <h2 class="text-sm font-medium">Available plans</h2>
                <p class="text-sm text-muted-foreground">
                    Prices are billed to the organization.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <Card v-for="plan in plans" :key="plan.key">
                    <CardHeader>
                        <div class="flex items-center justify-between gap-3">
                            <CardTitle>{{ plan.name }}</CardTitle>
                            <Badge
                                v-if="subscription?.plan?.key === plan.key"
                                variant="outline"
                            >
                                Current
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2">
                        <p
                            v-for="price in plan.prices"
                            :key="price.id"
                            class="text-2xl font-semibold tracking-tight"
                        >
                            {{ formatAmount(price) }}
                            <span
                                v-if="price.amount > 0"
                                class="text-sm font-normal text-muted-foreground"
                            >
                                / {{ price.interval }}
                            </span>
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>
    </div>
</template>
