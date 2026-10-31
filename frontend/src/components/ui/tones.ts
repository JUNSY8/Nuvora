export type Tone = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'danger' | 'muted'

// Full class strings so Tailwind can detect them at build time.
export const softBadge: Record<Tone, string> = {
  primary: 'bg-vz-primary/15 text-vz-primary-text',
  secondary: 'bg-vz-secondary/15 text-vz-secondary-text',
  success: 'bg-vz-success/15 text-vz-success-text',
  info: 'bg-vz-info/15 text-vz-info-text',
  warning: 'bg-vz-warning/15 text-vz-warning-text',
  danger: 'bg-vz-danger/15 text-vz-danger-text',
  muted: 'bg-white/10 text-vz-muted',
}

export const solidFill: Record<Tone, string> = {
  primary: 'bg-vz-primary',
  secondary: 'bg-vz-secondary',
  success: 'bg-vz-success',
  info: 'bg-vz-info',
  warning: 'bg-vz-warning',
  danger: 'bg-vz-danger',
  muted: 'bg-[#4b5058]',
}

export const toneText: Record<Tone, string> = {
  primary: 'text-vz-primary-text',
  secondary: 'text-vz-secondary-text',
  success: 'text-vz-success-text',
  info: 'text-vz-info-text',
  warning: 'text-vz-warning-text',
  danger: 'text-vz-danger-text',
  muted: 'text-vz-muted',
}

export const tileSurface: Record<Tone, string> = {
  primary: 'border-vz-primary/60 bg-vz-primary/15 text-vz-primary-text hover:bg-vz-primary/25',
  secondary: 'border-vz-secondary/60 bg-vz-secondary/15 text-vz-secondary-text hover:bg-vz-secondary/25',
  success: 'border-vz-success/50 bg-vz-success/10 text-vz-success-text hover:bg-vz-success/20',
  info: 'border-vz-info/60 bg-vz-info/15 text-vz-info-text hover:bg-vz-info/25',
  warning: 'border-vz-warning/60 bg-vz-warning/15 text-vz-warning-text hover:bg-vz-warning/25',
  danger: 'border-vz-danger/60 bg-vz-danger/15 text-vz-danger-text hover:bg-vz-danger/25',
  muted: 'border-white/15 bg-white/5 text-vz-muted hover:bg-white/10',
}
