<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, ref, useId, watch } from 'vue'

import { listCustomers } from '@/modules/customers/api'
import { customerType } from '@/modules/customers/labels'
import type { CustomerSummary } from '@/modules/customers/types'

const props = withDefaults(defineProps<{
  label: string
  organizationsOnly?: boolean
  excludeId?: string | null
  invalid?: boolean
  placeholder?: string
}>(), {
  organizationsOnly: false,
  excludeId: null,
  invalid: false,
  placeholder: 'Busca por nombre o documento',
})

const selected = defineModel<CustomerSummary | null>({ required: true })

const inputId = useId()
const listId = useId()
const query = ref('')
const debounced = refDebounced(query, 250)
const results = ref<CustomerSummary[]>([])
const open = ref(false)
const loading = ref(false)
const failed = ref(false)
const highlighted = ref(0)

const visible = computed(() => results.value.filter((customer) => customer.id !== props.excludeId))

async function search(): Promise<void> {
  loading.value = true
  failed.value = false

  try {
    const page = await listCustomers({
      search: debounced.value.trim() || undefined,
      status: 'active',
      organizations: props.organizationsOnly ? 1 : undefined,
    }, 1, 8)
    results.value = page.items.map(({ id, name, type, status }) => ({ id, name, type, status }))
    highlighted.value = 0
  } catch {
    results.value = []
    failed.value = true
  } finally {
    loading.value = false
  }
}

function choose(customer: CustomerSummary): void {
  selected.value = customer
  open.value = false
  query.value = ''
}

function onFocus(): void {
  open.value = true
  void search()
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    open.value = true
    highlighted.value = Math.min(highlighted.value + 1, visible.value.length - 1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    highlighted.value = Math.max(highlighted.value - 1, 0)
  } else if (event.key === 'Enter' && open.value && visible.value[highlighted.value]) {
    event.preventDefault()
    choose(visible.value[highlighted.value] as CustomerSummary)
  } else if (event.key === 'Escape' && open.value) {
    event.stopPropagation()
    open.value = false
  }
}

watch(debounced, () => {
  if (open.value) {
    void search()
  }
})
</script>

<template>
  <div>
    <label class="vz-label" :for="inputId">{{ label }}</label>
    <div v-if="selected" class="flex items-center justify-between gap-2 rounded-vz border border-vz-border bg-vz-input px-3 py-2">
      <span class="flex min-w-0 items-center gap-2 text-[13px]">
        <i :class="customerType[selected.type].icon" class="text-vz-muted" aria-hidden="true" />
        <span class="truncate font-medium text-vz-heading">{{ selected.name }}</span>
        <span class="text-xs text-vz-muted">{{ customerType[selected.type].label }}</span>
      </span>
      <button :id="inputId" class="vz-btn-icon size-7 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" :aria-label="`Quitar ${selected.name}`" @click="selected = null">
        <i class="ri-close-line" aria-hidden="true" />
      </button>
    </div>
    <div v-else class="relative">
      <i class="ri-search-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
      <input
        :id="inputId"
        v-model="query"
        class="vz-input ps-9"
        type="search"
        role="combobox"
        autocomplete="off"
        :placeholder="placeholder"
        :aria-expanded="open"
        :aria-controls="listId"
        :aria-invalid="invalid"
        :aria-activedescendant="open && visible[highlighted] ? `${listId}-${highlighted}` : undefined"
        @focus="onFocus"
        @blur="open = false"
        @keydown="onKeydown"
      />
      <ul v-if="open" :id="listId" class="vz-dropdown absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto py-1" role="listbox">
        <li v-if="loading" class="px-3 py-2 text-[13px] text-vz-muted">Buscando…</li>
        <li v-else-if="failed" class="px-3 py-2 text-[13px] text-vz-danger">No fue posible buscar clientes.</li>
        <li v-else-if="visible.length === 0" class="px-3 py-2 text-[13px] text-vz-muted">{{ organizationsOnly ? 'Sin empresas activas con ese criterio.' : 'Sin clientes activos con ese criterio.' }}</li>
        <li
          v-for="(customer, index) in visible"
          v-else
          :id="`${listId}-${index}`"
          :key="customer.id"
          class="flex cursor-pointer items-center gap-2 px-3 py-2 text-[13px]"
          :class="index === highlighted ? 'bg-vz-elevated text-vz-heading' : ''"
          role="option"
          :aria-selected="index === highlighted"
          @mousedown.prevent="choose(customer)"
          @mouseenter="highlighted = index"
        >
          <i :class="customerType[customer.type].icon" class="text-vz-muted" aria-hidden="true" />
          <span class="truncate">{{ customer.name }}</span>
          <span class="ms-auto text-xs text-vz-muted">{{ customerType[customer.type].label }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>
