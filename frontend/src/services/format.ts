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

export function formatTime(value: string | null, timeZone: string): string {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('es-CO', { timeStyle: 'short', timeZone }).format(new Date(value))
}

// "2 h 05 min", "45 min", "3 d 4 h". Whole minutes; the API counts a started minute as one.
export function formatDuration(minutes: number | null): string {
  if (minutes === null || !Number.isFinite(minutes) || minutes < 0) {
    return '—'
  }

  const total = Math.floor(minutes)
  const days = Math.floor(total / 1440)
  const hours = Math.floor((total % 1440) / 60)
  const rest = total % 60

  if (days > 0) {
    return hours > 0 ? `${days} d ${hours} h` : `${days} d`
  }

  if (hours > 0) {
    return `${hours} h ${String(rest).padStart(2, '0')} min`
  }

  return `${rest} min`
}

// Minutes elapsed since an ISO timestamp, as the API counts them (started minutes).
export function minutesSince(value: string, now: number = Date.now()): number {
  const seconds = Math.floor((now - new Date(value).getTime()) / 1000)

  return seconds <= 0 ? 0 : Math.ceil(seconds / 60)
}
