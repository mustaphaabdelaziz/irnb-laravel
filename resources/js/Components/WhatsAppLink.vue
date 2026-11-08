<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import { whatsAppDigits } from '@/lib/phone';

/**
 * WhatsApp icon that opens a chat with the number. On the web it is a plain
 * wa.me link in a new tab; in the desktop app a new tab would be a bare
 * Electron window, so the number goes to the server, which hands wa.me to the
 * OS browser (and from there to WhatsApp). With no usable number it renders a
 * plain grey phone icon.
 */
const props = defineProps({
    phone: { type: [String, Number], default: null },
});

const page = usePage();
const digits = computed(() => whatsAppDigits(props.phone));
const href = computed(() => (digits.value ? `https://wa.me/${digits.value}` : null));

function open(event) {
    if (!page.props.isDesktop) return; // the browser follows the link itself
    event.preventDefault();
    router.post(route('desktop.whatsapp'), { phone: digits.value }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <a
        v-if="href"
        :href="href"
        target="_blank"
        rel="noopener noreferrer"
        title="WhatsApp"
        aria-label="WhatsApp"
        class="inline-flex rounded text-[#25D366] transition hover:scale-110 hover:text-[#1da851] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
        @click="open"
    ><Icon name="whatsapp" /></a>
    <Icon v-else name="phone" class="text-slate-400" />
</template>
