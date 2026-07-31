/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  theme: {
    extend: {
      colors: {
        // Transoria brand palette — deep space slate + sky/teal signal.
        ink: {
          950: '#070b14',
          900: '#0b1120',
          800: '#111a2e',
          700: '#1b2740',
          600: '#26344f',
        },
        brand: {
          DEFAULT: '#38bdf8',
          soft: '#7dd3fc',
          deep: '#0ea5e9',
          glow: '#22d3ee',
        },
        gain: '#34d399',
        loss: '#fb7185',
        gold: '#fbbf24',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
      boxShadow: {
        glass: '0 8px 32px rgba(2, 6, 23, 0.6)',
        glow: '0 0 24px rgba(56, 189, 248, 0.35)',
      },
      keyframes: {
        'pulse-dot': {
          '0%, 100%': { opacity: 1 },
          '50%': { opacity: 0.35 },
        },
        'slide-up': {
          '0%': { opacity: 0, transform: 'translateY(8px)' },
          '100%': { opacity: 1, transform: 'translateY(0)' },
        },
      },
      animation: {
        'pulse-dot': 'pulse-dot 1.6s ease-in-out infinite',
        'slide-up': 'slide-up 0.35s ease-out',
      },
    },
  },
  plugins: [],
}
