<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { switchMethod } from '@/routes/organizations';

const page = usePage();
const { isMobile, state } = useSidebar();

const current = computed(() => page.props.organization);
const organizations = computed(() => page.props.organizations ?? []);

// A solo user has one organization, so the switcher is hidden for them rather
// than absent: the moment they are in a second one, it appears (D1).
const canSwitch = computed(() => organizations.value.length > 1);
</script>

<template>
    <SidebarMenu v-if="current">
        <SidebarMenuItem>
            <DropdownMenu v-if="canSwitch">
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="organization-switcher"
                    >
                        <Building2 class="size-4" />
                        <span class="truncate font-medium">{{
                            current.name
                        }}</span>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="isMobile ? 'bottom' : 'right'"
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground">
                        Organizations
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="organization in organizations"
                        :key="organization.id"
                        as-child
                    >
                        <Link
                            :href="switchMethod(organization.slug)"
                            method="post"
                            as="button"
                            class="w-full"
                            preserve-scroll
                        >
                            <span class="truncate">{{
                                organization.name
                            }}</span>
                            <Check
                                v-if="organization.id === current.id"
                                class="ml-auto size-4"
                            />
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <SidebarMenuButton
                v-else
                size="lg"
                data-test="organization-name"
                class="pointer-events-none"
            >
                <Building2 class="size-4" />
                <span class="truncate font-medium">{{ current.name }}</span>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
