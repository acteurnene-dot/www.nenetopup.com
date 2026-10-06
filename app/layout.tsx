import { Analytics } from '@vercel/analytics/next'
import type { Metadata, Viewport } from 'next'
import './globals.css'

export const metadata: Metadata = {
  metadataBase: new URL('https://nene-celeste.vercel.app'),
  title: 'Nene Celeste | Android, iPhone, Free Fire ak Proxy',
  description:
    'Nene Celeste ofri sèvis ak lisans pou Android, iPhone, Free Fire Beta, Proxy ak Miguel iOS. Kòmande fasil sou WhatsApp.',
  keywords: [
    'Nene Celeste',
    'Nene Celeste Haiti',
    'Android Haiti',
    'iPhone Haiti',
    'Free Fire Beta Haiti',
    'Proxy Haiti',
    'Miguel iOS iPhone',
  ],
  alternates: { canonical: '/' },
  openGraph: {
    title: 'Nene Celeste | Sèvis Android, iPhone ak Free Fire',
    description: 'Kòmande sèvis Nene Celeste pou Android, iPhone, Free Fire Beta ak Proxy.',
    url: 'https://nene-celeste.vercel.app',
    siteName: 'Nene Celeste',
    locale: 'ht_HT',
    type: 'website',
  },
  robots: { index: true, follow: true },
  generator: 'v0.app',
}

export const viewport: Viewport = {
  colorScheme: 'dark',
  themeColor: '#080b14',
  userScalable: true,
}

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="ht" className="bg-background">
      <body className="antialiased">
        {children}
        {process.env.NODE_ENV === 'production' && <Analytics />}
      </body>
    </html>
  )
}
