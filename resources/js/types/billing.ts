export type BillingState = 'active' | 'grace_period' | 'past_due' | 'inactive';

export type BillingPrice = {
    id: string;
    interval: 'day' | 'week' | 'month' | 'year';
    currency: string;
    amount: number;
};

export type BillingPlan = {
    key: string;
    name: string;
    prices: BillingPrice[];
};

export type CurrentSubscription = {
    state: BillingState;
    plan: Pick<BillingPlan, 'key' | 'name'> | null;
    price: BillingPrice | null;
    trialEndsAt: string | null;
    endsAt: string | null;
};

export type StripeSetup = {
    configured: boolean;
    setupHint: string | null;
};
