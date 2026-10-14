<script setup>
// The playing surface behind the line-up dots: football/futsal markings, else a
// plain court. viewBox units are metres of a 68 x 105 pitch; it stretches to fill.
defineProps({ sport: { type: String, default: 'football' } });
</script>

<template>
    <svg viewBox="0 0 68 105" preserveAspectRatio="none" class="absolute inset-0 h-full w-full" aria-hidden="true">
        <template v-if="sport === 'football' || sport === 'futsal'">
            <rect x="0" y="0" width="68" height="105" class="fill-emerald-600 dark:fill-emerald-800" />
            <g fill="none" stroke="white" stroke-width="0.4" opacity="0.85">
                <rect x="2" y="2" width="64" height="101" />
                <line x1="2" y1="52.5" x2="66" y2="52.5" />
                <circle cx="34" cy="52.5" r="9.15" />
                <rect x="13.85" y="2" width="40.3" height="16.5" />
                <rect x="24.85" y="2" width="18.3" height="5.5" />
                <rect x="13.85" y="86.5" width="40.3" height="16.5" />
                <rect x="24.85" y="97.5" width="18.3" height="5.5" />
            </g>
        </template>
        <template v-else>
            <rect x="0" y="0" width="68" height="105" class="fill-amber-200 dark:fill-amber-900" />
            <g fill="none" stroke="white" stroke-width="0.5" opacity="0.9">
                <rect x="2" y="2" width="64" height="101" />
                <line x1="2" y1="52.5" x2="66" y2="52.5" />
                <circle cx="34" cy="52.5" r="6" />
            </g>
        </template>
    </svg>
</template>
