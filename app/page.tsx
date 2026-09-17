'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { Check, ChevronDown, MessageCircle, ShoppingCart, Trash2, X } from 'lucide-react'

type Product = { name: string; duration: string; price: number; type: 'android' | 'iphone' | 'free-fire' | 'cuban-proxy' | 'miguel-ios' }
type PaymentMethod = 'MonCash'

const products: Product[] = [
  { name: 'Android Configuration', duration: '1 mois', price: 500, type: 'android' },
  { name: 'Android Configuration', duration: '3 mois', price: 1000, type: 'android' },
  { name: 'Android Configuration', duration: 'Illimité', price: 1500, type: 'android' },
  { name: 'Android Configuration', duration: 'Proxy illimité', price: 1000, type: 'android' },
  { name: 'iPhone Configuration', duration: '1 mois', price: 500, type: 'iphone' },
  { name: 'iPhone Configuration', duration: '3 mois', price: 1000, type: 'iphone' },
  { name: 'iPhone Configuration', duration: 'Illimité', price: 1500, type: 'iphone' },
  { name: 'iPhone Configuration', duration: 'Proxy illimité', price: 1000, type: 'iphone' },
  { name: 'Free Fire', duration: 'Beta', price: 500, type: 'free-fire' },
  { name: 'Cuban Proxy', duration: 'Lisans konplè', price: 1000, type: 'cuban-proxy' },
  { name: 'Miguel iOS iPhone', duration: '30 jou', price: 1500, type: 'miguel-ios' },
  { name: 'Miguel iOS iPhone', duration: '7 jou', price: 750, type: 'miguel-ios' },
]

const paymentAccounts: Record<PaymentMethod, string> = { MonCash: '47384728' }
const formatPrice = (price: number) => `${price.toLocaleString('fr-FR')} HTG`

export default function Page() {
  const [cart, setCart] = useState<Product[]>([])
  const [cartOpen, setCartOpen] = useState(false)
  const [activeType, setActiveType] = useState<'android' | 'iphone' | 'free-fire' | 'cuban-proxy' | 'miguel-ios' | null>(null)
  const [paymentMethod] = useState<PaymentMethod>('MonCash')
  const [reference, setReference] = useState('')
  const [verified, setVerified] = useState(false)
  const [verifying, setVerifying] = useState(false)
  const [error, setError] = useState('')
  const promoVideoRef = useRef<HTMLVideoElement>(null)

  useEffect(() => {
    const video = promoVideoRef.current
    if (!video) return
    video.muted = true
    const startPlayback = () => void video.play().catch(() => undefined)
    video.addEventListener('canplay', startPlayback)
    startPlayback()
    return () => video.removeEventListener('canplay', startPlayback)
  }, [])

  function enablePromoSound() {
    const video = promoVideoRef.current
    if (!video) return
    video.muted = false
    void video.play().catch(() => undefined)
  }

  const total = useMemo(() => cart.reduce((sum, product) => sum + product.price, 0), [cart])
  const activeProducts = products.filter((product) => product.type === activeType)

  function showPlans(type: 'android' | 'iphone' | 'free-fire' | 'cuban-proxy' | 'miguel-ios') {
    setActiveType(type)
    requestAnimationFrame(() => document.getElementById(`${type}-plans`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }

  function addToCart(product: Product) {
    setCart((current) => [...current, product])
    setCartOpen(true)
  }

  async function verifyMonCashPayment() {
    if (!cart.length || !reference.trim()) {
      setError('Tanpri antre transaction code MonCash la.')
      return
    }

    setVerifying(true)
    setVerified(false)
    setError('')
    try {
      const response = await fetch('/api/moncash/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ transactionId: reference.trim(), expectedAmount: total }),
      })
      const result = (await response.json().catch(() => ({}))) as { verified?: boolean; error?: string }
      if (!response.ok || !result.verified) throw new Error(result.error || 'MonCash pa konfime peman an.')
      setVerified(true)
    } catch (verificationError) {
      setError(verificationError instanceof Error ? verificationError.message : 'Verifikasyon MonCash echwe.')
    } finally {
      setVerifying(false)
    }
  }

  function checkout() {
    if (!cart.length || !verified) {
      setError('Verifye peman MonCash la anvan ou voye kòmand lan.')
      return
    }

    setError('')
    const lines = cart.map((product) => `• ${product.name} - ${product.duration} : ${formatPrice(product.price)}`).join('\n')
    const message = [
      'Bonjou NENE STORE ET CELESTE COMPANY.',
      '',
      'Mwen vle kòmande:',
      lines,
      '',
      `Metòd peman: ${paymentMethod}`,
      `Nimewo peman: ${paymentAccounts[paymentMethod]}`,
      `Referans tranzaksyon: ${reference.trim()}`,
      `Total: ${formatPrice(total)}`,
      '',
      'Peman an fèt manyèlman. Tanpri verifye li anvan livrezon sèvis la.',
    ].join('\n')
    window.open(`https://wa.me/50941591807?text=${encodeURIComponent(message)}`, '_blank', 'noopener,noreferrer')
  }

  function clearCart() {
    setCart([])
    setReference('')
    setVerified(false)
    setError('')
  }

  return (
    <div className="storefront">
      <header className="site-header"><div className="nav-shell"><a className="brand" href="#top" aria-label="Nene Store et Celeste Company">NENE STORE <span>ET CELESTE COMPANY</span></a><button className="cart-button" onClick={() => setCartOpen(true)} aria-label={`Louvri panier, ${cart.length} atik`}><ShoppingCart size={18} /><span>Panier</span><b>{cart.length}</b></button></div></header>
      <main id="top" className="container">
        <section className="hero" aria-labelledby="page-title"><div className="badge"><span className="flag-dot">HT</span> NENE STORE ET CELESTE COMPANY</div><p className="eyebrow">SERVICE RAPIDE · DISPONIB KI FÈ W KONFYANS</p><h1 id="page-title">CONFIGURATION<br /><em>pou telefòn ou.</em></h1><p className="hero-copy">Chwazi configuration ki koresponn ak telefòn ou.<br />Aktive sèvis ou fasil, an kèk klik.</p></section>
        <section className="promo-video-section" aria-labelledby="promo-video-title"><div className="section-heading"><span className="section-kicker">VIDEO</span><h2 id="promo-video-title">Proxy</h2></div><div className="promo-video-frame"><video ref={promoVideoRef} className="promo-video" autoPlay loop playsInline preload="auto" aria-label="Videyo prezantasyon Proxy"><source src="/proxy-promo.mp4" type="video/mp4" />Navigatè ou pa sipòte videyo sa a.</video><button className="video-sound-button" type="button" onClick={enablePromoSound} aria-label="Aktive son videyo a">Aktive son</button></div></section>
        <section className="configuration-section" aria-labelledby="config-title"><div className="section-heading"><span className="section-kicker">01</span><h2 id="config-title">Chwazi aparèy ou</h2></div><div className="device-grid">
          <button className={`device-card ${activeType === 'android' ? 'selected' : ''}`} onClick={() => showPlans('android')}><span className="device-icon android-icon"><img src="/android-logo.jpg" alt="Logo Android" /></span><span><strong>Android</strong><small>Logo Android · Configuration pou telefòn Android.</small></span><ChevronDown className="card-arrow" size={20} /></button>
          <button className={`device-card ${activeType === 'iphone' ? 'selected' : ''}`} onClick={() => showPlans('iphone')}><span className="device-icon iphone-icon"><img src="/apple-logo.jpg" alt="Logo Apple pou iPhone" /></span><span><strong>iPhone</strong><small>Logo Apple · Configuration pou iPhone.</small></span><ChevronDown className="card-arrow" size={20} /></button>
          <button className={`device-card ${activeType === 'free-fire' ? 'selected' : ''}`} onClick={() => showPlans('free-fire')}><span className="device-icon free-fire-icon"><img src="/free-fire-beta.jpg" alt="Logo Free Fire Beta" /></span><span><strong>Free Fire Beta</strong><small>Logo Free Fire · Aksè Beta pou jwè yo.</small></span><ChevronDown className="card-arrow" size={20} /></button>
          <button className={`device-card proxy-card ${activeType === 'cuban-proxy' ? 'selected' : ''}`} onClick={() => showPlans('cuban-proxy')}><span className="device-icon proxy-icon"><img src="/cuban-proxy-logo.jpg" alt="Logo Cuban Proxy" /></span><span><strong>Cuban Proxy</strong><small>Proxy Cuban avèk tout lisans li, trè legal.<br />No ban · No blacklist.</small></span><ChevronDown className="card-arrow" size={20} /></button>
          <button className={`device-card miguel-card ${activeType === 'miguel-ios' ? 'selected' : ''}`} onClick={() => showPlans('miguel-ios')}><span className="device-icon iphone-icon"><img src="/apple-logo.jpg" alt="Logo Apple pou Miguel iOS iPhone" /></span><span><strong>Miguel iOS iPhone</strong><small>Chwazi lisans ou pou iPhone.</small></span><ChevronDown className="card-arrow" size={20} /></button>
        </div></section>
        {activeType && <section id={`${activeType}-plans`} className="plans-section" aria-labelledby="plans-title"><div className="plans-heading"><div><span className="section-kicker">02</span><h2 id="plans-title">{activeType === 'android' ? 'Android' : activeType === 'iphone' ? 'iPhone' : activeType === 'free-fire' ? 'Free Fire Beta' : activeType === 'cuban-proxy' ? 'Cuban Proxy' : 'Miguel iOS iPhone'} {activeType === 'free-fire' || activeType === 'cuban-proxy' || activeType === 'miguel-ios' ? '' : 'Configuration'}</h2></div><button className="close-plans" onClick={() => setActiveType(null)}><X size={16} /> Fèmen</button></div><div className="plan-list">{activeProducts.map((product) => <div className="plan" key={product.duration}><div className="plan-info"><span className="check"><Check size={15} /></span><span><strong>{product.duration}</strong><small>Aktivasyon imedya</small></span></div><strong className="price">{formatPrice(product.price)}</strong><button className="add-button" onClick={() => addToCart(product)}>Ajouter</button></div>)}</div></section>}
      </main>
      <footer><strong>NENE STORE <span>ET CELESTE COMPANY</span></strong><p>Kesyon? Kontakte nou sou WhatsApp</p><a href="https://wa.me/50941591807">+509 4159-1807 <MessageCircle size={16} /></a></footer>
      {cartOpen && <div className="cart-backdrop" onClick={() => setCartOpen(false)} aria-hidden="true" />}
      <aside className={`cart-box ${cartOpen ? 'active' : ''}`} aria-label="Panier"><div className="cart-header"><div><span className="section-kicker">PANIER</span><h2>Atik ou chwazi yo</h2></div><button onClick={() => setCartOpen(false)} aria-label="Fèmen panier"><X size={20} /></button></div>
        {cart.length === 0 ? <p className="empty-cart">Panier vid. Chwazi yon configuration dabò.</p> : <div className="cart-items">{cart.map((product, index) => <div className="cart-item" key={`${product.duration}-${index}`}><span>{product.name}<small>{product.duration}</small></span><strong>{formatPrice(product.price)}</strong></div>)}</div>}
        <div className="total"><span>Total</span><strong>{formatPrice(total)}</strong></div>
        {cart.length > 0 && <div className="payment-area"><p className="payment-title">03 · Peye epi konfime kòmand ou</p><p className="payment-help">Voye {formatPrice(total)} sou MonCash, apre sa antre transaction code la pou verifikasyon otomatik.</p><div className="payment-options"><div className="payment-option active"><strong>MonCash</strong><small>47384728</small><span>Otomatik</span></div></div><p className="payment-note"><strong>MonCash:</strong> voye peman an sou <strong>47384728</strong>. Sistèm nan verifye montan ak status tranzaksyon an.</p><label className="reference-label" htmlFor="reference">Transaction code MonCash <span>(obligatwa)</span></label><input id="reference" className="reference-input" value={reference} onChange={(event) => { setReference(event.target.value); setVerified(false); setError('') }} placeholder="Egzanp: 123456789" inputMode="numeric" />{error && <p className="payment-error" role="alert">{error}</p>}{verified && <p className="payment-success" role="status">Peman MonCash verifye avèk siksè.</p>}<button className="verify-payment" type="button" onClick={verifyMonCashPayment} disabled={verifying || !reference.trim()}>{verifying ? 'Ap verifye...' : verified ? 'Peman verifye' : 'Verifye peman MonCash'}</button><p className="payment-note">Apre verifikasyon an, detay kòmand lan ap ouvri sou WhatsApp pou <strong>50941591807</strong>.</p></div>}
        <button className="checkout" onClick={checkout} disabled={!cart.length || !verified}><MessageCircle size={18} /> Voye kòmand MonCash</button>{cart.length > 0 && <button className="clear" onClick={clearCart}><Trash2 size={15} /> Vide panier</button>}
      </aside>
    </div>
  )
}
