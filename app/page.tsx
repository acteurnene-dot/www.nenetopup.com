'use client'

import { useEffect, useMemo, useState } from 'react'
import { Apple, Check, ChevronDown, MessageCircle, ShoppingCart, Smartphone, Trash2, X } from 'lucide-react'

type Product = { name: string; duration: string; price: number }
type PaymentMethod = 'NatCash' | 'MonCash'

const products: Product[] = [
  { name: 'Android Configuration', duration: '1 mois', price: 500 },
  { name: 'Android Configuration', duration: '3 mois', price: 1000 },
  { name: 'Android Configuration', duration: 'Illimité', price: 1500 },
  { name: 'Android Configuration', duration: 'Proxy illimité', price: 1000 },
  { name: 'iPhone Configuration', duration: '1 mois', price: 500 },
  { name: 'iPhone Configuration', duration: '3 mois', price: 1000 },
  { name: 'iPhone Configuration', duration: 'Illimité', price: 1500 },
  { name: 'iPhone Configuration', duration: 'Proxy illimité', price: 1000 },
]

const paymentAccounts: Record<PaymentMethod, string> = { NatCash: '41591807', MonCash: '47384728' }
const formatPrice = (price: number) => `${price.toLocaleString('fr-FR')} HTG`

export default function Page() {
  const [cart, setCart] = useState<Product[]>([])
  const [cartOpen, setCartOpen] = useState(false)
  const [activeType, setActiveType] = useState<'android' | 'iphone' | null>(null)
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('NatCash')
  const [reference, setReference] = useState('')
  const [paymentScreenshot, setPaymentScreenshot] = useState<File | null>(null)
  const [paymentScreenshotUrl, setPaymentScreenshotUrl] = useState('')
  const [uploadedScreenshotUrl, setUploadedScreenshotUrl] = useState('')
  const [isUploading, setIsUploading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!paymentScreenshot) {
      setPaymentScreenshotUrl('')
      return
    }
    const url = URL.createObjectURL(paymentScreenshot)
    setPaymentScreenshotUrl(url)
    return () => URL.revokeObjectURL(url)
  }, [paymentScreenshot])

  const total = useMemo(() => cart.reduce((sum, product) => sum + product.price, 0), [cart])
  const activeProducts = products.filter((product) => product.name.toLowerCase().startsWith(activeType ?? ''))

  function showPlans(type: 'android' | 'iphone') {
    setActiveType(type)
    requestAnimationFrame(() => document.getElementById(`${type}-plans`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }

  function addToCart(product: Product) {
    setCart((current) => [...current, product])
    setCartOpen(true)
  }

  async function checkout() {
    if (!cart.length || isUploading) return
    if (!paymentScreenshot) {
      setError('Tanpri chwazi screenshot prèv peman an.')
      return
    }

    setError('')
    setIsUploading(true)
    try {
      let screenshotUrl = uploadedScreenshotUrl
      if (!screenshotUrl) {
        const formData = new FormData()
        formData.append('file', paymentScreenshot)
        const response = await fetch('/api/upload', { method: 'POST', body: formData })
        const result = await response.json()
        if (!response.ok || !result.url) throw new Error(result.error || 'Upload foto a echwe.')
        screenshotUrl = result.url
        setUploadedScreenshotUrl(screenshotUrl)
      }

      const lines = cart.map((product) => `• ${product.name} - ${product.duration} : ${formatPrice(product.price)}`).join('\n')
      const message = [
        'Bonjou NENE STORE ET CELESTE COMPANY.',
        '',
        'Mwen vle kòmande:',
        lines,
        '',
        `Metòd peman: ${paymentMethod}`,
        `Nimewo peman: ${paymentAccounts[paymentMethod]}`,
        `Referans tranzaksyon: ${reference.trim() || 'Pa bay'}`,
        `Foto prèv peman an: ${screenshotUrl}`,
        `Total: ${formatPrice(total)}`,
      ].join('\n')
      window.open(`https://wa.me/50941591807?text=${encodeURIComponent(message)}`, '_blank', 'noopener,noreferrer')
    } catch (uploadError) {
      setError(uploadError instanceof Error ? uploadError.message : 'Upload foto a echwe. Eseye ankò.')
    } finally {
      setIsUploading(false)
    }
  }

  function clearCart() {
    setCart([])
    setReference('')
    setPaymentScreenshot(null)
    setPaymentScreenshotUrl('')
    setUploadedScreenshotUrl('')
    setIsUploading(false)
    setError('')
  }

  return (
    <div className="storefront">
      <header className="site-header"><div className="nav-shell"><a className="brand" href="#top" aria-label="Nene Store et Celeste Company">NENE STORE <span>ET CELESTE COMPANY</span></a><button className="cart-button" onClick={() => setCartOpen(true)} aria-label={`Louvri panier, ${cart.length} atik`}><ShoppingCart size={18} /><span>Panier</span><b>{cart.length}</b></button></div></header>
      <main id="top" className="container">
        <section className="hero" aria-labelledby="page-title"><div className="badge"><span className="flag-dot">HT</span> NENE STORE ET CELESTE COMPANY</div><p className="eyebrow">SERVICE RAPIDE · DISPONIB KI FÈ W KONFYANS</p><h1 id="page-title">CONFIGURATION<br /><em>pou telefòn ou.</em></h1><p className="hero-copy">Chwazi configuration ki koresponn ak telefòn ou.<br />Aktive sèvis ou fasil, an kèk klik.</p></section>
        <section className="configuration-section" aria-labelledby="config-title"><div className="section-heading"><span className="section-kicker">01</span><h2 id="config-title">Chwazi aparèy ou</h2></div><div className="device-grid">
          <button className={`device-card ${activeType === 'android' ? 'selected' : ''}`} onClick={() => showPlans('android')}><span className="device-icon android-icon"><Smartphone size={34} /></span><span><strong>Android</strong><small>Configuration pou telefòn Android.</small></span><ChevronDown className="card-arrow" size={20} /></button>
          <button className={`device-card ${activeType === 'iphone' ? 'selected' : ''}`} onClick={() => showPlans('iphone')}><span className="device-icon iphone-icon"><Apple size={34} /></span><span><strong>iPhone</strong><small>Configuration pou iPhone.</small></span><ChevronDown className="card-arrow" size={20} /></button>
        </div></section>
        {activeType && <section id={`${activeType}-plans`} className="plans-section" aria-labelledby="plans-title"><div className="plans-heading"><div><span className="section-kicker">02</span><h2 id="plans-title">{activeType === 'android' ? 'Android' : 'iPhone'} Configuration</h2></div><button className="close-plans" onClick={() => setActiveType(null)}><X size={16} /> Fèmen</button></div><div className="plan-list">{activeProducts.map((product) => <div className="plan" key={product.duration}><div className="plan-info"><span className="check"><Check size={15} /></span><span><strong>{product.duration}</strong><small>Aktivasyon imedya</small></span></div><strong className="price">{formatPrice(product.price)}</strong><button className="add-button" onClick={() => addToCart(product)}>Ajouter</button></div>)}</div></section>}
      </main>
      <footer><strong>NENE STORE <span>ET CELESTE COMPANY</span></strong><p>Kesyon? Kontakte nou sou WhatsApp</p><a href="https://wa.me/50941591807">+509 4159-1807 <MessageCircle size={16} /></a></footer>
      {cartOpen && <div className="cart-backdrop" onClick={() => setCartOpen(false)} aria-hidden="true" />}
      <aside className={`cart-box ${cartOpen ? 'active' : ''}`} aria-label="Panier"><div className="cart-header"><div><span className="section-kicker">PANIER</span><h2>Atik ou chwazi yo</h2></div><button onClick={() => setCartOpen(false)} aria-label="Fèmen panier"><X size={20} /></button></div>
        {cart.length === 0 ? <p className="empty-cart">Panier vid. Chwazi yon configuration dabò.</p> : <div className="cart-items">{cart.map((product, index) => <div className="cart-item" key={`${product.duration}-${index}`}><span>{product.name}<small>{product.duration}</small></span><strong>{formatPrice(product.price)}</strong></div>)}</div>}
        <div className="total"><span>Total</span><strong>{formatPrice(total)}</strong></div>
        {cart.length > 0 && <div className="payment-area"><p className="payment-title">03 · Peye epi konfime kòmand ou</p><p className="payment-help">Voye {formatPrice(total)} sou youn nan nimewo ki anba a, apre sa antre referans tranzaksyon an.</p><div className="payment-options">{(['NatCash', 'MonCash'] as PaymentMethod[]).map((method) => <button key={method} className={`payment-option ${paymentMethod === method ? 'active' : ''}`} onClick={() => { setPaymentMethod(method); setError('') }}><strong>{method}</strong><small>{paymentAccounts[method]}</small></button>)}</div><label className="reference-label" htmlFor="reference">Referans tranzaksyon <span>(opsyonèl)</span></label><input id="reference" className="reference-input" value={reference} onChange={(event) => { setReference(event.target.value); setError('') }} placeholder="Egzanp: NC123456" /><label className="reference-label" htmlFor="payment-screenshot">Screenshot prèv peman</label><input id="payment-screenshot" className="reference-input" type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => { setPaymentScreenshot(event.target.files?.[0] ?? null); setUploadedScreenshotUrl(''); setError('') }} />{paymentScreenshot && paymentScreenshotUrl && <div className="screenshot-preview"><img src={paymentScreenshotUrl} alt="Preview screenshot prèv peman" /><div><strong>{paymentScreenshot.name}</strong><span>Foto a pare pou ajoute sou WhatsApp.</span></div></div>}{error && <p className="payment-error" role="alert">{error}</p>}<p className="payment-note">Apre ou klike, detay yo ap ouvri sou WhatsApp pou <strong>50941591807</strong>.</p></div>}
        <button className="checkout" onClick={checkout} disabled={!cart.length || isUploading}><MessageCircle size={18} /> {isUploading ? 'Upload foto a...' : 'Voye kòmand sou WhatsApp'}</button>{cart.length > 0 && <button className="clear" onClick={clearCart}><Trash2 size={15} /> Vide panier</button>}
      </aside>
    </div>
  )
}
