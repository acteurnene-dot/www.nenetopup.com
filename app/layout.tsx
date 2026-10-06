import { Analytics } from '@vercel/analytics/next'
import type { Metadata, Viewport } from 'next'
import './globals.css'

export const metadata: Metadata = {
  metadataBase: new URL('https://www.nenetopup.com'),
  title: {
    default: 'Nene Store et Celeste Company | Top Up Ayiti',
    template: '%s | Nene Store et Celeste Company',
  },
  description: 'Nene Store et Celeste Company ofri configuration Android, iPhone, Free Fire Beta ak Cuban Proxy ann Ayiti. Kòmande fasil sou WhatsApp.',
  keywords: [
    'Nene Store',
    'Celeste Company',
    'top up Haiti',
    'configuration Android Haiti',
    'configuration iPhone Haiti',
    'Free Fire Beta Haiti',
    'Cuban Proxy Haiti',
  ],
  alternates: { canonical: '/' },
  openGraph: {
    type: 'website',
    locale: 'ht_HT',
    url: 'https://www.nenetopup.com',
    siteName: 'Nene Store et Celeste Company',
    title: 'Nene Store et Celeste Company | Top Up Ayiti',
    description: 'Configuration ak sèvis dijital pou Android, iPhone ak Free Fire Beta ann Ayiti.',
  },
  robots: { index: true, follow: true },
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
