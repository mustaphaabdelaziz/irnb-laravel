<script setup>
import { computed } from 'vue';

/**
 * Single cohesive icon set — hand-drawn on a 24px grid with one stroke weight,
 * replacing the emoji that used to stand in for icons. Line icons inherit
 * `currentColor`; brand/social glyphs render filled. No external dependency.
 */
const props = defineProps({
    name: { type: String, required: true },
    strokeWidth: { type: [Number, String], default: 1.6 },
});

// Brand glyphs are solid; everything else is a stroked line icon.
const SOLID = new Set(['facebook', 'x', 'youtube', 'linkedin', 'tiktok']);

const icons = {
    dashboard: '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',
    players: '<circle cx="8.5" cy="9" r="3"/><path d="M3 19.5a5.5 5.5 0 0 1 11 0"/><path d="M15.5 6.4a3 3 0 0 1 0 5.2"/><path d="M16.2 14.2A5.5 5.5 0 0 1 21 19.5"/>',
    subscriptions: '<path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h15A1.5 1.5 0 0 1 21 8.5v2a2 2 0 0 0 0 4v2A1.5 1.5 0 0 1 19.5 18h-15A1.5 1.5 0 0 1 3 16.5v-2a2 2 0 0 0 0-4v-2Z"/><path d="M14 7.5v10" stroke-dasharray="1.5 2.5"/>',
    transactions: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v5M18 9.5v5"/>',
    equipment: '<circle cx="12" cy="12" r="9"/><polygon points="12,8.4 15.2,10.7 14,14.4 10,14.4 8.8,10.7"/><path d="M12 3v2.2M4.7 8.4l2.1 1.5M19.3 8.4l-2.1 1.5M6.8 19.1l1.3-2.1M17.2 19.1l-1.3-2.1"/>',
    members: '<circle cx="10" cy="9" r="3"/><path d="M4.5 19a5.5 5.5 0 0 1 11 0"/><path d="M15.8 11.6l1.7 1.7 3.2-3.4"/>',
    categories: '<path d="M3.5 11.3V5.5A2 2 0 0 1 5.5 3.5h5.8a2 2 0 0 1 1.4.6l7 7a2 2 0 0 1 0 2.8l-5.8 5.8a2 2 0 0 1-2.8 0l-7-7a2 2 0 0 1-.6-1.4Z"/><circle cx="8" cy="8" r="1.4"/>',
    jobs: '<rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M8.5 7.5V6a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v1.5"/><path d="M3 12.5h18"/>',
    positions: '<path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
    location: '<path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
    settings: '<path d="M5 6h14M5 12h14M5 18h14"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="8" cy="18" r="2"/>',
    home: '<path d="M4 11.5 12 4l8 7.5"/><path d="M5.5 10.5V19a1 1 0 0 0 1 1H10v-5h4v5h3.5a1 1 0 0 0 1-1v-8.5"/>',
    external: '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v4.5A1.5 1.5 0 0 1 16.5 20h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
    document: '<path d="M7 3.5h6l4.5 4.5V19a1.5 1.5 0 0 1-1.5 1.5H7A1.5 1.5 0 0 1 5.5 19V5A1.5 1.5 0 0 1 7 3.5Z"/><path d="M13 3.5V8h4.5"/><path d="M8.5 13h7M8.5 16h7"/>',
    mail: '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m4 7 8 6 8-6"/>',
    phone: '<path d="M6.5 4h3l1.5 4-2 1.5a11 11 0 0 0 5 5l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 6a2 2 0 0 1 2-2Z"/>',
    check: '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    alert: '<path d="M12 4 2.5 20.5h19L12 4Z"/><path d="M12 10v4.2"/><circle cx="12" cy="17.3" r="0.9" fill="currentColor" stroke="none"/>',
    money: '<circle cx="12" cy="12" r="9"/><path d="M12 6.8v10.4"/><path d="M14.6 9.2c-.5-.9-1.5-1.4-2.6-1.4-1.6 0-2.6.9-2.6 2 0 2.6 5.2 1.4 5.2 4 0 1.2-1.1 2-2.6 2-1.1 0-2.1-.5-2.6-1.4"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    chevron: '<path d="m6 9 6 6 6-6"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    logout: '<path d="M14 4H6.5A1.5 1.5 0 0 0 5 5.5v13A1.5 1.5 0 0 0 6.5 20H14"/><path d="M10 12h10M16.5 8.5 20 12l-3.5 3.5"/>',
    user: '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.5-3.5"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.3M12 19.2v2.3M21.5 12h-2.3M4.8 12H2.5M18.4 5.6l-1.6 1.6M7.2 16.8l-1.6 1.6M18.4 18.4l-1.6-1.6M7.2 7.2 5.6 5.6"/>',
    moon: '<path d="M20 13.5A8 8 0 1 1 10.5 4a6.3 6.3 0 0 0 9.5 9.5Z"/>',
    back: '<path d="M15 6l-6 6 6 6"/>',
    box: '<path d="M12 3 4 7.2v9.6L12 21l8-4.2V7.2L12 3Z"/><path d="M4 7.2 12 11.4l8-4.2M12 11.4V21"/>',
    refresh: '<path d="M3.5 11A8 8 0 0 1 17 6l3 2.5"/><path d="M20 4.5V9h-4.5"/><path d="M20.5 13A8 8 0 0 1 7 18l-3-2.5"/><path d="M4 19.5V15h4.5"/>',
    wrench: '<path d="M15.5 7.5a3.5 3.5 0 0 1-4.4 4.4l-4.8 4.8a1.8 1.8 0 0 0 2.5 2.5l4.8-4.8a3.5 3.5 0 0 0 4.4-4.4l-2.3 2.3-1.9-.5-.5-1.9 2.2-2.2Z"/>',
    xcircle: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
    archive: '<rect x="3" y="4.5" width="18" height="4" rx="1.2"/><path d="M5 8.5V18a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 18V8.5"/><path d="M10 12h4"/>',
    print: '<path d="M7 9V4h10v5"/><rect x="4" y="9" width="16" height="7" rx="1.5"/><path d="M7 14h10v5H7z"/>',
    upload: '<path d="M12 16V5M8 9l4-4 4 4"/><path d="M5 19h14"/>',
    download: '<path d="M12 5v11M8 12l4 4 4-4"/><path d="M5 19h14"/>',
    instagram: '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/>',
    facebook: '<path d="M13.5 21v-7.5H16l.4-3h-2.9V8.6c0-.87.24-1.46 1.49-1.46H16.5V4.43c-.28-.04-1.25-.12-2.37-.12-2.35 0-3.96 1.43-3.96 4.07V10.5H7.7v3h2.47V21h3.33Z"/>',
    x: '<path d="M3.5 3.5h4l4.2 5.9 4.8-5.9h2.2l-6 7.4 6.5 9.1h-4l-4.5-6.3-5.1 6.3H3.4l6.5-8L3.5 3.5Z"/>',
    youtube: '<path d="M21.5 8.2a2.5 2.5 0 0 0-1.76-1.77C18.2 6 12 6 12 6s-6.2 0-7.74.43A2.5 2.5 0 0 0 2.5 8.2 26 26 0 0 0 2.25 12a26 26 0 0 0 .25 3.8 2.5 2.5 0 0 0 1.76 1.77C5.8 18 12 18 12 18s6.2 0 7.74-.43a2.5 2.5 0 0 0 1.76-1.77A26 26 0 0 0 21.75 12a26 26 0 0 0-.25-3.8ZM10 14.8V9.2l4.8 2.8-4.8 2.8Z"/>',
    linkedin: '<path d="M6.94 7.5a1.94 1.94 0 1 1 0-3.88 1.94 1.94 0 0 1 0 3.88ZM5.3 9h3.3v10.5H5.3V9Zm5.1 0h3.16v1.44h.04c.44-.83 1.5-1.7 3.1-1.7 3.32 0 3.93 2.18 3.93 5.02v5.74h-3.3v-5.09c0-1.21-.02-2.77-1.69-2.77-1.69 0-1.95 1.32-1.95 2.68v5.18H10.4V9Z"/>',
    tiktok: '<path d="M16 3c.3 2.1 1.5 3.6 3.5 3.9V10c-1.3.1-2.5-.3-3.5-1v5.6c0 3.4-2.6 5.6-5.6 5.4-2.8-.2-4.8-2.6-4.5-5.5.3-2.6 2.6-4.4 5.1-4v3.2c-.4-.1-.8-.2-1.2-.1-1 .2-1.7 1-1.6 2 .1 1 1 1.8 2 1.7 1.1-.1 1.8-.9 1.8-2.1V3H16Z"/>',
    dot: '<circle cx="12" cy="12" r="3"/>',
};

const body = computed(() => icons[props.name] ?? icons.dot);
const isSolid = computed(() => SOLID.has(props.name));
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        width="1em"
        height="1em"
        :fill="isSolid ? 'currentColor' : 'none'"
        :stroke="isSolid ? 'none' : 'currentColor'"
        :stroke-width="isSolid ? undefined : strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="inline-block shrink-0"
        aria-hidden="true"
        v-html="body"
    />
</template>
