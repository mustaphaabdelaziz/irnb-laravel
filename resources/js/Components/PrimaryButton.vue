<template>
    <button
        class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm ring-1 ring-inset ring-primary-500/40 transition duration-150 ease-out hover:bg-primary-700 hover:shadow-glow focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 active:scale-[0.98] active:bg-primary-800 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100"
    >
        <slot />
    </button>
</template>
