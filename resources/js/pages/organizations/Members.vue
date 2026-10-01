<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import InvitationController from '@/actions/App/Http/Controllers/Organizations/InvitationController';
import InvitationDeliveryController from '@/actions/App/Http/Controllers/Organizations/InvitationDeliveryController';
import MemberController from '@/actions/App/Http/Controllers/Organizations/MemberController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import OrganizationField from '@/components/OrganizationField.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
    PendingInvitation,
    RankOption,
} from '@/types/invitation';
import type { Paginated } from '@/types/pagination';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Members', href: index() }],
    },
});

defineProps<{
    members: Paginated<OrganizationMember>;
    invitations: Paginated<PendingInvitation>;
    canInvite: boolean;
    ranks: RankOption[];
}>();

const withdrawing = ref<PendingInvitation | null>(null);
const removing = ref<OrganizationMember | null>(null);
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
                class="flex flex-col gap-3 sm:flex-row sm:items-end"
                v-slot="{ errors, processing }"
            >
                <OrganizationField />
                <div class="grid flex-1 gap-2">
                    <Label for="email">Email address</Label>
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
                    <Label for="role">Role</Label>
                    <Select name="role" default-value="member">
                        <SelectTrigger id="role" class="w-full sm:w-40">
                            <SelectValue placeholder="Role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="rank in ranks"
                                :key="rank.value"
                                :value="rank.value"
                            >
                                {{ rank.label }}
                            </SelectItem>
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
                        <!-- Ownership implies admin rank, so the owner gets one
                             badge rather than two describing the same fact. -->
                        <Badge v-if="member.isOwner">Owner</Badge>
                        <Badge
                            v-else-if="!member.canManage"
                            variant="secondary"
                        >
                            {{ member.roleLabel }}
                        </Badge>

                        <Form
                            v-else
                            v-bind="MemberController.update.form(member.id)"
                            v-slot="{ submit }"
                        >
                            <OrganizationField />
                            <Select
                                :model-value="member.role"
                                :name="'role'"
                                @update:model-value="
                                    (value) => value !== member.role && submit()
                                "
                            >
                                <SelectTrigger
                                    class="h-11 w-32"
                                    :aria-label="`Role for ${member.name}`"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="rank in ranks"
                                        :key="rank.value"
                                        :value="rank.value"
                                    >
                                        {{ rank.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </Form>

                        <Badge
                            v-if="member.status !== 'active'"
                            variant="outline"
                        >
                            {{ member.status }}
                        </Badge>

                        <Button
                            v-if="member.canManage"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="h-11 text-destructive hover:text-destructive"
                            @click="removing = member"
                        >
                            {{ member.isYou ? 'Leave' : 'Remove' }}
                        </Button>
                    </div>
                </li>
            </ul>

            <Pagination :links="members.links" label="Members pages" />
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
                            Expires {{ invitation.expiresAt }}
                            <template v-if="invitation.invitedBy">
                                · invited by {{ invitation.invitedBy }}
                            </template>
                        </p>
                    </div>

                    <div v-if="canInvite" class="flex items-center gap-2">
                        <Badge variant="secondary">{{
                            invitation.roleLabel
                        }}</Badge>

                        <Form
                            v-bind="
                                InvitationDeliveryController.store.form(
                                    invitation.id,
                                )
                            "
                            v-slot="{ processing }"
                        >
                            <OrganizationField />
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                class="h-11"
                                :disabled="processing"
                            >
                                Resend
                            </Button>
                        </Form>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="h-11 text-destructive hover:text-destructive"
                            @click="withdrawing = invitation"
                        >
                            Withdraw
                        </Button>
                    </div>
                </li>
            </ul>

            <Pagination :links="invitations.links" label="Invitation pages" />
        </section>
    </div>

    <Dialog
        :open="removing !== null"
        @update:open="(open) => !open && (removing = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{
                        removing?.isYou
                            ? 'Leave this organization?'
                            : 'Remove this member?'
                    }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="removing?.isYou">
                        You will lose access to this organization immediately.
                        Somebody who manages members can invite you back.
                    </template>
                    <template v-else>
                        {{ removing?.name }} will lose access to this
                        organization immediately. You can invite them again
                        later.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="outline">Cancel</Button>
                </DialogClose>

                <Form
                    v-if="removing"
                    v-bind="MemberController.destroy.form(removing.id)"
                    @success="removing = null"
                    v-slot="{ processing }"
                >
                    <OrganizationField />
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        {{
                            removing.isYou
                                ? processing
                                    ? 'Leaving…'
                                    : 'Leave'
                                : processing
                                  ? 'Removing…'
                                  : 'Remove'
                        }}
                    </Button>
                </Form>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="withdrawing !== null"
        @update:open="(open) => !open && (withdrawing = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Withdraw this invitation?</DialogTitle>
                <DialogDescription>
                    {{ withdrawing?.email }} will no longer be able to use the
                    link that was sent to them. You can invite them again later.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="outline">Keep it</Button>
                </DialogClose>

                <Form
                    v-if="withdrawing"
                    v-bind="InvitationController.destroy.form(withdrawing.id)"
                    @success="withdrawing = null"
                    v-slot="{ processing }"
                >
                    <OrganizationField />
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        {{ processing ? 'Withdrawing…' : 'Withdraw' }}
                    </Button>
                </Form>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
