<script setup lang="ts">
import AppConfigController from '@/actions/App/Http/Controllers/AppConfigController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import SeoHead from '@/layouts/SeoHead.vue';
import { useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/**
 * Patch 46: the desktop app's settings for everyone. Every copy of the app reads them at start
 * and every half hour.
 */
type TAppConfig = {
    motd: string | null;
    min_version: string | null;
    features: Record<string, boolean>;
    updated_by: string | null;
    updated_at: string | null;
};

const props = defineProps<{ config: TAppConfig; features: Record<string, string> }>();

const form = useForm({
    motd: props.config.motd ?? '',
    min_version: props.config.min_version ?? '',
    features: { ...props.config.features },
});

function save(): void {
    form.transform((data) => ({ ...data, motd: data.motd.trim() || null, min_version: data.min_version.trim() || null })).put(
        AppConfigController.update().url,
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Saved: apps pick it up within half an hour (or when they start)'),
        },
    );
}
</script>

<template>
    <AppLayout>
        <SeoHead title="App admin" />
        <div class="mx-auto flex max-w-2xl flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Desktop app · admin</h1>
                <p class="text-sm text-muted-foreground">
                    Settings for every copy of the Devil's Rage app.
                    <template v-if="config.updated_by">Last changed by {{ config.updated_by }}.</template>
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Message of the day</CardTitle>
                    <CardDescription>Shown on the app's start screen. Leave empty for none.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-2">
                    <Textarea v-model="form.motd" rows="3" maxlength="500" placeholder="Ops tonight at 20:00 EVE, form up in Daisy" />
                    <p v-if="form.errors.motd" class="text-xs text-destructive">{{ form.errors.motd }}</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Minimum version</CardTitle>
                    <CardDescription>Apps older than this ask their pilot to update. Leave empty for no minimum.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-2">
                    <Label for="min-version" class="sr-only">Minimum version</Label>
                    <Input id="min-version" v-model="form.min_version" placeholder="0.1.4" class="max-w-40" />
                    <p v-if="form.errors.min_version" class="text-xs text-destructive">{{ form.errors.min_version }}</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Features</CardTitle>
                    <CardDescription>Switching one off turns it off in every copy of the app, whatever its own settings say.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div v-for="(label, key) in features" :key="key" class="flex items-center justify-between gap-4">
                        <Label :for="`feature-${key}`">{{ label }}</Label>
                        <Switch :id="`feature-${key}`" :model-value="form.features[key] !== false" @update:model-value="(on: boolean) => (form.features[key] = on)" />
                    </div>
                </CardContent>
            </Card>

            <div class="flex justify-end">
                <Button :disabled="form.processing" @click="save">Save</Button>
            </div>
        </div>
    </AppLayout>
</template>
