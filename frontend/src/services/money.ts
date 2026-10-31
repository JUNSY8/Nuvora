// The API exchanges money as integers in the currency's minor unit (ISO 4217
// exponent): 5.000 COP travels as 500000. Forms show and accept major units.
// The step mirrors the backend's Currency: pesos are charged whole.
const known: Record<string, { exponent: number; step: number }> = {
  COP: { exponent: 2, step: 100 },
  CLP: { exponent: 0, step: 1 },
  PYG: { exponent: 0, step: 1 },
  JPY: { exponent: 0, step: 1 },
}

function rules(currency: string): { exponent: number; step: number } {
  return known[currency.toUpperCase()] ?? { exponent: 2, step: 1 }
}

// Decimals a person types for the currency: none for pesos, two for dollars.
export function inputDecimals(currency: string): number {
  const { exponent, step } = rules(currency)

  return Math.max(0, exponent - Math.round(Math.log10(step)))
}

export function formatMoney(minor: number | null | undefined, currency: string): string {
  if (minor === null || minor === undefined || !Number.isFinite(minor)) {
    return '—'
  }

  const { exponent } = rules(currency)
  const decimals = inputDecimals(currency)

  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: currency.toUpperCase(),
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(minor / 10 ** exponent)
}

// Major units for an input field: 500000 COP -> "5000".
export function toMajorInput(minor: number | null | undefined, currency: string): string {
  if (minor === null || minor === undefined) {
    return ''
  }

  const { exponent } = rules(currency)

  return (minor / 10 ** exponent).toFixed(inputDecimals(currency))
}

// Parses what a person typed ("5.000", "5000", "12,50") into minor units.
// Returns null when it is empty, negative, malformed or not chargeable.
export function toMinorUnits(value: string, currency: string): number | null {
  const decimals = inputDecimals(currency)
  let text = value.trim().replace(/\s|\$/g, '')

  if (text === '') {
    return null
  }

  if (decimals === 0) {
    // Without decimals, dots and commas can only be thousands separators.
    text = text.replace(/[.,]/g, '')
  } else {
    const separator = Math.max(text.lastIndexOf(','), text.lastIndexOf('.'))
    const integer = separator === -1 ? text : text.slice(0, separator)
    const fraction = separator === -1 ? '' : text.slice(separator + 1)
    text = fraction.length > 0 && fraction.length <= decimals ? `${integer.replace(/[.,]/g, '')}.${fraction}` : text.replace(/[.,]/g, '')
  }

  if (!/^\d+(\.\d+)?$/.test(text)) {
    return null
  }

  const { exponent, step } = rules(currency)
  const minor = Math.round(Number(text) * 10 ** exponent)

  return Number.isSafeInteger(minor) && minor % step === 0 ? minor : null
}
