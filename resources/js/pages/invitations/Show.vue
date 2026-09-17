<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AcceptInvitationController from '@/actions/App/Http/Controllers/Invitations/AcceptInvitationController';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';

defineOptions({
    layout: {
        title: "You've been invited",
    },
});

const props = defineProps<{
    token: string;
    organization: string;
    email: string;
    invitedBy: string | null;
    expiresAt: string;
    // Why this invitation cannot be taken by whoever is signed in. Rendered
    // rather than thrown: somebody signed in to the wrong account needs to be
    // told which account, not shown an error page.
    refusal: string | null;
    authenticated: boolean;
}>();

const expiresOn = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
}).format(new Date(props.expiresAt));
</script>

<template>
    <Head title="Invitation" />

    <div class="flex flex-col gap-6">
        <p class="text-sm text-muted-foreground">
            <template v-if="invitedBy">{{ invitedBy }} invited</template>
            <template v-else>You have been invited</template>
            <strong class="font-medium text-foreground">
                {{ ' ' }}{{ email }}{{ ' ' }}
            </strong>
            to join
            <strong class="font-medium text-foreground">
                {{ organization }}</strong
            >.
        </p>

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

            <p class="text-center text-xs text-muted-foreground">
                This invitation expires on {{ expiresOn }}.
            </p>
        </template>

        <template v-else>
            <!-- The token is already parked in the session, so registering or
                 signing in finishes the job without coming back here. -->
            <Button as-child class="w-full">
                <a :href="register.url({ query: { email } })">
                    Create an account
                </a>
            </Button>

            <p class="text-center text-sm text-muted-foreground">
                Already have one?
                <TextLink :href="login()">Log in</TextLink>
            </p>

            <p class="text-center text-xs text-muted-foreground">
                This invitation expires on {{ expiresOn }}.
            </p>
        </template>
    </div>
</template>
