import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Inter renders Latin, Cairo/Noto cover Arabic glyphs — the browser
                // picks per-glyph, so a single stack works for the mixed-script UI.
                sans: ['Inter', 'Cairo', '"Noto Sans Arabic"', ...defaultTheme.fontFamily.sans],
                display: ['Cairo', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                border: 'hsl(var(--border) / <alpha-value>)',
                input: 'hsl(var(--input) / <alpha-value>)',
                ring: 'rgb(var(--ring) / <alpha-value>)',
                background: 'hsl(var(--background) / <alpha-value>)',
                foreground: 'hsl(var(--foreground) / <alpha-value>)',
                card: {
                    DEFAULT: 'hsl(var(--card) / <alpha-value>)',
                    foreground: 'hsl(var(--card-foreground) / <alpha-value>)',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--secondary) / <alpha-value>)',
                    foreground: 'hsl(var(--secondary-foreground) / <alpha-value>)',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted) / <alpha-value>)',
                    foreground: 'hsl(var(--muted-foreground) / <alpha-value>)',
                },
                accent: {
                    DEFAULT: 'hsl(var(--accent) / <alpha-value>)',
                    foreground: 'hsl(var(--accent-foreground) / <alpha-value>)',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--destructive) / <alpha-value>)',
                    foreground: 'hsl(var(--destructive-foreground) / <alpha-value>)',
                },
                primary: {
                    DEFAULT: 'rgb(var(--primary) / <alpha-value>)',
                    foreground: 'hsl(var(--primary-foreground) / <alpha-value>)',
                    50: 'rgb(var(--color-primary-50) / <alpha-value>)',
                    100: 'rgb(var(--color-primary-100) / <alpha-value>)',
                    200: 'rgb(var(--color-primary-200) / <alpha-value>)',
                    300: 'rgb(var(--color-primary-300) / <alpha-value>)',
                    400: 'rgb(var(--color-primary-400) / <alpha-value>)',
                    500: 'rgb(var(--color-primary-500) / <alpha-value>)',
                    600: 'rgb(var(--color-primary-600) / <alpha-value>)',
                    700: 'rgb(var(--color-primary-700) / <alpha-value>)',
                    800: 'rgb(var(--color-primary-800) / <alpha-value>)',
                    900: 'rgb(var(--color-primary-900) / <alpha-value>)',
                    950: 'rgb(var(--color-primary-950) / <alpha-value>)',
                },
                // "Stadium at night" — the sidebar / dark surfaces.
                night: {
                    950: '#080d0b',
                    900: '#0d1613',
                    800: '#13201b',
                    700: '#1b2d26',
                    600: '#274037',
                    500: '#35564a',
                },
            },
            boxShadow: {
                // Tinted with slate hue (not pure black) so elevation reads warm, not harsh.
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                'card-hover': '0 12px 32px -8px rgb(15 23 42 / 0.14), 0 4px 10px -4px rgb(15 23 42 / 0.08)',
                soft: '0 2px 8px -2px rgb(15 23 42 / 0.06), 0 8px 24px -12px rgb(15 23 42 / 0.10)',
                glow: '0 0 0 1px rgb(var(--color-primary-500) / 0.2), 0 8px 24px -6px rgb(var(--color-primary-600) / 0.35)',
                // Inner edge-light for glass surfaces.
                glass: 'inset 0 1px 0 0 rgb(255 255 255 / 0.08), 0 8px 32px -8px rgb(0 0 0 / 0.4)',
            },
            backgroundImage: {
                // Fine film grain (data-URI) to break digital flatness on dark surfaces.
                grain: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.5'/%3E%3C/svg%3E\")",
            },
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(8px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'rise-in': {
                    '0%': { opacity: '0', transform: 'translateY(14px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'scale-in': {
                    '0%': { opacity: '0', transform: 'scale(0.96)' },
                    '100%': { opacity: '1', transform: 'scale(1)' },
                },
                'glow-pulse': {
                    '0%, 100%': { opacity: '0.55' },
                    '50%': { opacity: '0.9' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.35s cubic-bezier(0.16, 1, 0.3, 1) both',
                'rise-in': 'rise-in 0.55s cubic-bezier(0.16, 1, 0.3, 1) both',
                'scale-in': 'scale-in 0.4s cubic-bezier(0.16, 1, 0.3, 1) both',
                'glow-pulse': 'glow-pulse 6s ease-in-out infinite',
            },
        },
    },

    plugins: [forms],
};
