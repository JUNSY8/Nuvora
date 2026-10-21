export function formatDateTime(value: string | null, timeZone: string): string {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(value))
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat('es-CO').format(value)
}

export function initials(name: string | undefined): string {
  if (!name) {
    return '?'
  }

  return name
    .split(/\s+/)
    .filter((part) => part !== '')
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}
