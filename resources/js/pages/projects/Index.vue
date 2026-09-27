<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Clock3 } from '@lucide/vue';
import { ref } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import ProjectLimitPrompt from '@/components/ProjectLimitPrompt.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/projects';
import type { Paginated, Project, ProjectAllowance } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Projects', href: index() }],
    },
});

const props = defineProps<{
    projects: Paginated<Project>;
    allowance: ProjectAllowance;
    planChangePending: boolean;
    canCreate: boolean;
    accessEndsAt: string | null;
}>();

const organization = usePage().props.organization;

// A create that is retried — a double submit, a refresh, a flaky connection —
// must return the project the first attempt made rather than spend a second
// unit of allowance. The token changes only once a create has landed.
const idempotencyToken = ref(crypto.randomUUID());

function renewIdempotencyToken(): void {
    idempotencyToken.value = crypto.randomUUID();
}

function allowanceLabel(allowance: ProjectAllowance): string {
    if (allowance.limit === null) {
        return `${allowance.usage} projects · unlimited on this plan`;
    }

    return `${allowance.usage} of ${allowance.limit} projects used`;
}
</script>

<template>
    <Head title="Projects" />

    <div class="flex flex-col space-y-8 p-4">
        <Heading
            variant="small"
            title="Projects"
            description="What this organization is building, and how much of its plan that uses"
        />

        <Alert v-if="accessEndsAt">
            <Clock3 class="size-4" />
            <AlertTitle>This plan ends {{ accessEndsAt }}</AlertTitle>
            <AlertDescription>
                Projects stay at the paid allowance until then, and fall back to
                the free allowance afterwards. Nothing is deleted.
            </AlertDescription>
        </Alert>

        <Alert v-if="planChangePending">
            <Clock3 class="size-4" />
            <AlertTitle>Your plan change is being applied.</AlertTitle>
            <AlertDescription>
                The new allowance shows here once it is confirmed. Refresh the
                page in a moment.
            </AlertDescription>
        </Alert>

        <ProjectLimitPrompt
            v-else-if="allowance.remaining === 0"
            :allowance="allowance"
        />

        <section v-if="canCreate" class="space-y-4">
            <h2 class="text-sm font-medium">Create a project</h2>

            <Form
                v-bind="ProjectController.store.form()"
                reset-on-success
                class="flex flex-col gap-3 sm:flex-row sm:items-end"
                v-slot="{ errors, processing }"
                @success="renewIdempotencyToken"
            >
                <input
                    type="hidden"
                    name="organization"
                    :value="organization?.slug"
                />
                <input
                    type="hidden"
                    name="idempotency_token"
                    :value="idempotencyToken"
                />

                <div class="grid flex-1 gap-2">
                    <Label for="name">Project name</Label>
                    <Input
                        id="name"
                        name="name"
                        type="text"
                        required
                        maxlength="255"
                        autocomplete="off"
                        placeholder="Launch checklist"
                    />
                    <InputError :message="errors.name ?? errors.project" />
                </div>

                <Button
                    type="submit"
                    :disabled="processing || allowance.remaining === 0"
                >
                    {{ processing ? 'Creating…' : 'Create project' }}
                </Button>
            </Form>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium">
                Projects
                <span class="text-muted-foreground">
                    ({{ allowanceLabel(props.allowance) }})
                </span>
            </h2>

            <p
                v-if="projects.data.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                This organization has no projects yet.
            </p>

            <ul v-else class="divide-y rounded-xl border">
                <li
                    v-for="project in projects.data"
                    :key="project.id"
                    class="flex flex-wrap items-center justify-between gap-2 p-4"
                >
                    <p class="min-w-0 truncate text-sm font-medium">
                        {{ project.name }}
                    </p>
                    <p
                        v-if="project.createdAt"
                        class="text-sm text-muted-foreground"
                    >
                        Created {{ project.createdAt }}
                    </p>
                </li>
            </ul>

            <Pagination :links="projects.links" label="Projects pages" />
        </section>
    </div>
</template>
