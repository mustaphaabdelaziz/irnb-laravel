<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email ?? '',
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                {{ t('profile_information') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                {{ t('profile_information_hint') }}
            </p>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="mt-6 space-y-6"
        >
            <!-- The sign-in username is set by an administrator from the users page. -->
            <div>
                <InputLabel for="username" :value="t('username')" />
                <TextInput id="username" type="text" class="mt-1 block w-full" :model-value="user.username ?? ''" disabled />
            </div>

            <div>
                <InputLabel for="name" :value="t('name')" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" :value="t('email')" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    autocomplete="email"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>


            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">{{ t('save') }}</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-gray-600"
                    >
                        {{ t('saved') }}
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
