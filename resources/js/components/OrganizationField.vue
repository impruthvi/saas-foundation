<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

// Names the organization this page was rendered for, so a submit after another tab
// switched organization is refused instead of landing in the other one.
const organization = usePage().props.organization;
</script>

<template>
    <input type="hidden" name="organization" :value="organization?.slug" />
</template>
