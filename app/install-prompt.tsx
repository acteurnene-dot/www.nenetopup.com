'use client'

import { useEffect, useState } from 'react'

type InstallEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> }

export default function InstallPrompt() {
  const [installEvent, setInstallEvent] = useState<InstallEvent | null>(null)
  const [installed, setInstalled] = useState(false)
  const [showHelp, setShowHelp] = useState(false)

  useEffect(() => {
    const onBeforeInstall = (event: Event) => {
      event.preventDefault()
      setInstallEvent(event as InstallEvent)
    }

    setInstalled(window.matchMedia('(display-mode: standalone)').matches || Boolean((window.navigator as Navigator & { standalone?: boolean }).standalone))
    window.addEventListener('beforeinstallprompt', onBeforeInstall)
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {})

    return () => window.removeEventListener('beforeinstallprompt', onBeforeInstall)
  }, [])

  if (installed) return null

  async function handleInstall() {
    if (!installEvent) {
      setShowHelp((current) => !current)
      return
    }

    await installEvent.prompt()
    setInstallEvent(null)
  }

  return (
    <div className="install-wrap">
      <button className="install-button" onClick={handleInstall} aria-expanded={showHelp}>
        <span className="install-icon" aria-hidden="true">↓</span>
        <span className="install-copy">
          <strong>Enstale NENE Store</strong>
          <small>Ajoute l sou telefòn ou</small>
        </span>
        <span className="install-arrow" aria-hidden="true">→</span>
      </button>
      {showHelp && !installEvent && (
        <div className="install-help" role="status">
          <strong>Kijan pou enstale</strong>
          <span>Android: ouvri meni navigatè a epi chwazi “Add to Home screen”. iPhone: peze Share, apre sa “Add to Home Screen”.</span>
        </div>
      )}
    </div>
  )
}
