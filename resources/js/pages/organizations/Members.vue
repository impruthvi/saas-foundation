<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InvitationController from '@/actions/App/Http/Controllers/Organizations/InvitationController';
import InvitationDeliveryController from '@/actions/App/Http/Controllers/Organizations/InvitationDeliveryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/organizations/members';
import type {
    OrganizationMember,
    Paginated,
    PendingInvitation,
} from '@/types/invitation';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Members', href: index() }],
    },
});

defineProps<{
    members: Paginated<OrganizationMember>;
    invitations: Paginated<PendingInvitation>;
    canInvite: boolean;
}>();

const expiry = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
});

function expiresOn(iso: string): string {
    return expiry.format(new Date(iso));
}
</script>

<template>
    <Head title="Members" />

    <div class="flex flex-col space-y-8 p-4">
        <Heading
            variant="small"
            title="Members"
            description="Who is in this organization, and who has been invited"
        />

        <section v-if="canInvite" class="space-y-4">
            <h2 class="text-sm font-medium">Invite someone</h2>

            <Form
                v-bind="InvitationController.store.form()"
                reset-on-success
                class="flex flex-col gap-3 sm:flex-row sm:items-start"
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2">
                    <Label class="sr-only" for="email">Email address</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="off"
                        placeholder="teammate@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label class="sr-only" for="role">Role</Label>
                    <Select name="role" default-value="member">
                        <SelectTrigger id="role" class="w-full sm:w-40">
                            <SelectValue placeholder="Role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="member">Member</SelectItem>
                            <SelectItem value="admin">Admin</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.role" />
                </div>

                <Button type="submit" :disabled="processing">
                    {{ processing ? 'Sending…' : 'Send invitation' }}
                </Button>
            </Form>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium">
                Members
                <span class="text-muted-foreground">({{ members.total }})</span>
            </h2>

            <ul class="divide-y rounded-xl border">
                <li
                    v-for="member in members.data"
                    :key="member.id"
                    class="flex flex-wrap items-center justify-between gap-2 p-4"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ member.name }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ member.email }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Badge v-if="member.isOwner" variant="default">
                            Owner
                        </Badge>
                        <Badge variant="secondary">{{ member.role }}</Badge>
                        <Badge
                            v-if="member.status !== 'active'"
                            variant="outline"
                        >
                            {{ member.status }}
                        </Badge>
                    </div>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium">
                Pending invitations
                <span class="text-muted-foreground">
                    ({{ invitations.total }})
                </span>
            </h2>

            <p
                v-if="invitations.data.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nobody is waiting on an invitation.
            </p>

            <ul v-else class="divide-y rounded-xl border">
                <li
                    v-for="invitation in invitations.data"
                    :key="invitation.id"
                    class="flex flex-wrap items-center justify-between gap-2 p-4"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ invitation.email }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            Expires {{ expiresOn(invitation.expiresAt) }}
                            <template v-if="invitation.invitedBy">
                                · invited by {{ invitation.invitedBy }}
                            </template>
                        </p>
                    </div>

                    <div v-if="canInvite" class="flex items-center gap-2">
                        <Badge variant="secondary">{{ invitation.role }}</Badge>

                        <Link
                            v-bind="
                                InvitationDeliveryController.store.form(
                                    invitation.id,
                                )
                            "
                            as="button"
                            class="text-sm underline-offset-4 hover:underline"
                        >
                            Resend
                        </Link>

                        <Link
                            v-bind="
                                InvitationController.destroy.form(invitation.id)
                            "
                            as="button"
                            class="text-sm text-destructive underline-offset-4 hover:underline"
                        >
                            Withdraw
                        </Link>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
