<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AcceptInvitationController from '@/actions/App/Http/Controllers/Invitations/AcceptInvitationController';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';

const props = defineProps<{
    token: string;
    organization: string;
    email: string;
    invitedBy: string | null;
    expiresAt: string;
    // Why this invitation cannot be taken by whoever is signed in. Rendered
    // rather than thrown: somebody signed in to the wrong account needs to be
    // told which account.
    refusal: string | null;
    authenticated: boolean;
}>();

defineOptions({
    layout: {
        title: '',
    },
});
</script>

<template>
    <Head title="Invitation" />

    <div class="flex flex-col gap-6">
        <header class="space-y-2 text-center">
            <p class="text-sm text-muted-foreground">
                You've been invited to join
            </p>
            <h1 class="text-2xl leading-tight font-semibold">
                {{ organization }}
            </h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="invitedBy">{{ invitedBy }} invited</template>
                <template v-else>Invitation sent to</template>
                {{ ' ' }}{{ email }}
            </p>
        </header>

        <p
            v-if="refusal"
            class="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
        >
            {{ refusal }}
        </p>

        <template v-else-if="authenticated">
            <Form
                v-bind="AcceptInvitationController.store.form(token)"
                v-slot="{ processing }"
            >
                <Button type="submit" class="w-full" :disabled="processing">
                    {{ processing ? 'Joining…' : `Join ${organization}` }}
                </Button>
            </Form>

            <Form
                v-bind="AcceptInvitationController.destroy.form(token)"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    variant="ghost"
                    class="w-full"
                    :disabled="processing"
                >
                    Decline
                </Button>
            </Form>
        </template>

        <template v-else>
            <!-- The token is parked in the session, so registering or signing in
                 finishes the job without returning here. -->
            <Button as-child class="w-full">
                <a :href="register.url({ query: { email } })">
                    Join {{ organization }}
                </a>
            </Button>

            <p class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink :href="login()">Log in</TextLink>
            </p>
        </template>

        <p class="text-center text-xs text-muted-foreground">
            This invitation expires on {{ props.expiresAt }}.
        </p>
    </div>
</template>
