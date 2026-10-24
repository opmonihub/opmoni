(() => {
  const canvas = document.createElement('canvas')
  canvas.width = canvas.height = 1
  const context = canvas.getContext('2d', { willReadFrequently: true })
  const rgba = (color) => {
    context.clearRect(0, 0, 1, 1)
    context.fillStyle = color
    context.fillRect(0, 0, 1, 1)
    return [...context.getImageData(0, 0, 1, 1).data].map((value, index) => index === 3 ? value / 255 : value)
  }
  const over = (fg, bg) => fg.slice(0, 3).map((value, index) => value * fg[3] + bg[index] * (1 - fg[3])).concat(1)
  const luminance = rgb => rgb.slice(0, 3).map(value => value / 255).map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0)
  const contrast = (a, b) => { const aa = luminance(a), bb = luminance(b); return (Math.max(aa, bb) + 0.05) / (Math.min(aa, bb) + 0.05) }
  const backdrop = (element) => {
    const stack = []
    for (let current = element; current; current = current.parentElement) stack.unshift(current)
    return stack.reduce((bg, current) => over(rgba(getComputedStyle(current).backgroundColor), bg), [255, 255, 255, 1])
  }
  const measure = (element, role, pseudo = null) => {
    const style = getComputedStyle(element, pseudo), bg = backdrop(element), fg = over(rgba(style.color), bg), r = element.getBoundingClientRect()
    return { role, tag: element.tagName, label: element.getAttribute('aria-label') || element.textContent?.trim().slice(0, 80), color: style.color, backgroundColor: style.backgroundColor, effectiveForeground: fg.slice(0, 3), effectiveBackground: bg.slice(0, 3), contrast: Number(contrast(fg, bg).toFixed(3)), outlineColor: style.outlineColor, outlineWidth: style.outlineWidth, outlineStyle: style.outlineStyle, boxShadow: style.boxShadow, opacity: style.opacity, disabled: !!element.disabled, contrastApplicability: element.disabled ? 'inactive control; opacity reported separately' : 'normal text', focusVisible: element.matches(':focus-visible'), rect: { x: r.x, y: r.y, width: r.width, height: r.height } }
  }
  const visible = el => el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0
  const roles = []
  for (const element of document.querySelectorAll('button,a')) {
    if (!visible(element)) continue
    if (element.classList.contains('bg-primary')) roles.push(measure(element, 'primary-action'))
    else if (element.classList.contains('bg-inverted')) roles.push(measure(element, 'neutral-action'))
    if (element.classList.contains('text-primary') && !element.classList.contains('bg-primary')) roles.push(measure(element, element.getAttribute('aria-current') ? 'active-navigation' : 'primary-text-action'))
  }
  for (const element of document.querySelectorAll('span,div,p')) {
    if (!visible(element)) continue
    if (element.classList.contains('text-success')) roles.push(measure(element, 'success-status'))
    if (element.classList.contains('text-muted') && element.children.length === 0) roles.push(measure(element, 'muted-copy'))
  }
  for (const element of document.querySelectorAll('input,textarea')) {
    if (!visible(element)) continue
    roles.push(measure(element, 'input-text'))
    if (element.placeholder) roles.push(measure(element, 'placeholder', '::placeholder'))
  }
  const dialog = [...document.querySelectorAll('[role=dialog]')].find(visible)
  const style = getComputedStyle(document.documentElement)
  const tokens = Object.fromEntries(['--ui-primary','--ui-success','--ui-text','--ui-text-muted','--ui-text-dimmed','--ui-text-inverted','--ui-bg','--ui-bg-elevated'].map(token => [token, style.getPropertyValue(token).trim()]))
  return { path: location.pathname, mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light', hydrated: !!document.querySelector('#__nuxt')?.__vue_app__, tokens, rootFontSize: style.fontSize, bodyFont: getComputedStyle(document.body).fontFamily, erpMounted: !!document.querySelector('[data-erp-workspace]'), dialog: dialog ? { text: dialog.getAttribute('aria-label') || dialog.querySelector('h2')?.textContent, outsideErp: !dialog.closest('[data-erp-workspace]'), role: measure(dialog, 'overlay') } : null, roles }
})()
