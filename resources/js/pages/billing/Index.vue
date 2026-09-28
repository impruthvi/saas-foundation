<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { CircleAlert, Clock3 } from '@lucide/vue';
import { ref } from 'vue';
import CheckoutController from '@/actions/App/Http/Controllers/Billing/CheckoutController';
import SubscriptionController from '@/actions/App/Http/Controllers/Billing/SubscriptionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { index } from '@/routes/organizations/billing';
import type {
    BillingPlan,
    BillingPrice,
    BillingState,
    CurrentSubscription,
    StripeSetup,
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
    stripe: StripeSetup;
}>();

const organization = usePage().props.organization;
const confirmingCancellation = ref(false);

const stateLabels: Record<BillingState, string> = {
    active: 'Active',
    grace_period: 'Ending',
    past_due: 'Past due',
    inactive: 'Inactive',
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
                                ['past_due', 'inactive'].includes(
                                    subscription.state,
                                )
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

                    <Alert
                        v-else-if="subscription.state === 'inactive'"
                        variant="destructive"
                    >
                        <CircleAlert />
                        <AlertTitle>Subscription is not active</AlertTitle>
                        <AlertDescription>
                            Its payment was never completed or has lapsed, so
                            the plan does not apply. An administrator should
                            resolve the payment with the billing provider.
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

                <CardFooter v-if="canManageBilling" class="border-t">
                    <Button
                        v-if="subscription.state !== 'grace_period'"
                        type="button"
                        variant="outline"
                        class="text-destructive hover:text-destructive"
                        @click="confirmingCancellation = true"
                    >
                        Cancel subscription
                    </Button>

                    <Form
                        v-else
                        v-bind="SubscriptionController.update.form()"
                        v-slot="{ errors, processing }"
                        class="flex flex-col gap-2"
                    >
                        <input
                            type="hidden"
                            name="organization"
                            :value="organization?.slug"
                        />
                        <Button type="submit" :disabled="processing">
                            {{
                                processing ? 'Resuming…' : 'Resume subscription'
                            }}
                        </Button>
                        <InputError :message="errors.billing" />
                    </Form>
                </CardFooter>
            </Card>
        </section>

        <Alert v-if="!stripe.configured">
            <CircleAlert />
            <AlertTitle>Stripe is not configured</AlertTitle>
            <AlertDescription>
                {{
                    stripe.setupHint ??
                    'Subscriptions open once an administrator connects a Stripe account.'
                }}
            </AlertDescription>
        </Alert>

        <section class="flex flex-col gap-4">
            <div>
                <h2 class="text-sm font-medium">Available plans</h2>
                <p class="text-sm text-muted-foreground">
                    Prices are billed to the organization.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <Card v-for="plan in plans" :key="plan.key" class="h-full">
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
                    <CardContent class="flex flex-col gap-4">
                        <div
                            v-for="price in plan.prices"
                            :key="price.id"
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <p class="text-2xl font-semibold tracking-tight">
                                {{ formatAmount(price) }}
                                <span
                                    v-if="price.amount > 0"
                                    class="text-sm font-normal text-muted-foreground"
                                >
                                    / {{ price.interval }}
                                </span>
                            </p>

                            <Form
                                v-if="
                                    canManageBilling &&
                                    stripe.configured &&
                                    !subscription &&
                                    price.amount > 0
                                "
                                v-bind="CheckoutController.store.form()"
                                v-slot="{ errors, processing }"
                                class="flex flex-col gap-2"
                            >
                                <input
                                    type="hidden"
                                    name="organization"
                                    :value="organization?.slug"
                                />
                                <input
                                    type="hidden"
                                    name="price"
                                    :value="price.id"
                                />
                                <Button type="submit" :disabled="processing">
                                    {{
                                        processing
                                            ? 'Opening checkout…'
                                            : `Subscribe to ${plan.name}`
                                    }}
                                </Button>
                                <InputError
                                    :message="errors.billing ?? errors.price"
                                />
                            </Form>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </section>

        <Dialog
            :open="confirmingCancellation"
            @update:open="(open) => (confirmingCancellation = open)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel this subscription?</DialogTitle>
                    <DialogDescription>
                        The organization keeps its current plan until the end of
                        the billing period. You can resume before then.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">Keep subscription</Button>
                    </DialogClose>

                    <Form
                        v-bind="SubscriptionController.destroy.form()"
                        @success="confirmingCancellation = false"
                        v-slot="{ errors, processing }"
                        class="flex flex-col gap-2"
                    >
                        <input
                            type="hidden"
                            name="organization"
                            :value="organization?.slug"
                        />
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Scheduling…'
                                    : 'Cancel subscription'
                            }}
                        </Button>
                        <InputError :message="errors.billing" />
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
