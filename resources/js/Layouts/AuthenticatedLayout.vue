<script setup>
// A page's part of the application frame: its header (teleported into the top
// bar of AppShell, the persistent layout around every signed-in page — see
// app.js) and the main column. The sidebar and top bar themselves stay
// mounted while pages change.
//
// No entrance animation: the content fading in for 0.35 s after it was ready
// was the largest delay seen on every click.
</script>

<template>
    <Teleport defer to="#app-page-header">
        <slot name="header" />
    </Teleport>
    <main class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
        <slot />
    </main>
</template>
