<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'

const props = withDefaults(defineProps<{
  open: boolean
  title: string
  size?: 'sm' | 'md' | 'lg'
}>(), {
  size: 'md',
})

const emit = defineEmits<{ close: [] }>()

const dialog = ref<HTMLElement | null>(null)
const titleId = useId()
let previouslyFocused: HTMLElement | null = null

const widths = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl' }

function focusables(): HTMLElement[] {
  return Array.from(dialog.value?.querySelectorAll<HTMLElement>(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  ) ?? [])
}

// Escape is handled on the document so it still works when focus drops to the
// body (for example after a clicked button disables itself). Dialogs are
// teleported to the body in opening order, so only the last one reacts and a
// confirmation closes before the dialog beneath it.
function isTopmost(): boolean {
  const dialogs = document.querySelectorAll('[role="dialog"]')

  return dialogs.length > 0 && dialogs[dialogs.length - 1] === dialog.value
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Escape' || !isTopmost()) {
    return
  }

  event.preventDefault()
  emit('close')
}

function register(): void {
  document.addEventListener('keydown', onDocumentKeydown)
}

function unregister(): void {
  document.removeEventListener('keydown', onDocumentKeydown)
}

function anyDialogOpen(): boolean {
  return document.querySelector('[role="dialog"]') !== null
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Tab') {
    return
  }

  const items = focusables()
  const first = items[0]
  const last = items[items.length - 1]

  if (!first || !last) {
    return
  }

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(() => props.open, async (open) => {
  if (open) {
    previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
    document.body.style.overflow = 'hidden'
    register()
    await nextTick()
    const autofocus = dialog.value?.querySelector<HTMLElement>('[autofocus]')
    ;(autofocus ?? focusables()[0] ?? dialog.value)?.focus()
  } else {
    unregister()
    await nextTick()

    if (!anyDialogOpen()) {
      document.body.style.overflow = ''
    }

    previouslyFocused?.focus()
  }
}, { immediate: true })

onBeforeUnmount(() => {
  unregister()
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-black/60 px-4 py-10" @mousedown.self="emit('close')">
      <div
        ref="dialog"
        class="vz-card w-full"
        :class="widths[size]"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
        @keydown="onKeydown"
      >
        <div class="flex items-center justify-between gap-3 border-b border-vz-border px-5 py-4">
          <h2 :id="titleId" class="text-[15px] font-semibold text-vz-heading">{{ title }}</h2>
          <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" aria-label="Cerrar" @click="emit('close')">
            <i class="ri-close-line" aria-hidden="true" />
          </button>
        </div>
        <slot />
      </div>
    </div>
  </Teleport>
</template>
