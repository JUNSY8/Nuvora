<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import StatusBadge from '@/components/ui/StatusBadge.vue'
import { listReservations } from '@/modules/reservations/api'
import { reservationStatus, scopeLabel } from '@/modules/reservations/labels'
import type { Reservation } from '@/modules/reservations/types'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import { formatTime, toLocalInput } from '@/services/format'

// Today's reservations still to arrive, for the operator at the gate. The
// entry links the reservation by plate; this list only tells who is coming.
const props = defineProps<{ lotId: string; timezone: string; version: number }>()

const items = ref<Reservation[]>([])
const total = ref(0)
const failed = ref(false)

async function load(): Promise<void> {
  const today = toLocalInput(new Date(), props.timezone).slice(0, 10)

  try {
    const result = await listReservations({ lot_id: props.lotId, status: ['pending', 'confirmed'], from_date: today, to_date: today }, 1, 6)
    items.value = result.items
    total.value = result.meta.total
    failed.value = false
  } catch {
    failed.value = true
  }
}

watch(() => [props.lotId, props.version], load, { immediate: true })
</script>

<template>
  <section class="vz-card mt-5" aria-labelledby="arrivals-title">
    <div class="flex items-center justify-between gap-2 border-b border-vz-border px-4 py-3">
      <h2 id="arrivals-title" class="vz-card-title"><i class="ri-calendar-check-line text-vz-primary-text" aria-hidden="true" /> Llegadas con reserva hoy</h2>
      <RouterLink class="text-xs text-vz-secondary-text hover:underline" :to="{ name: 'reservations' }">Ver agenda</RouterLink>
    </div>
    <p v-if="failed" class="p-4 text-[13px] text-vz-muted">No fue posible consultar las reservas.</p>
    <p v-else-if="items.length === 0" class="p-4 text-[13px] text-vz-muted">No hay más llegadas reservadas para hoy.</p>
    <ul v-else class="divide-y divide-vz-border">
      <li v-for="reservation in items" :key="reservation.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
        <div class="min-w-0">
          <PlateChip :plate="reservation.vehicle.plate" :country="reservation.vehicle.country" />
          <p class="mt-1 truncate text-xs text-vz-muted">{{ formatTime(reservation.starts_at, timezone) }} · {{ scopeLabel(reservation) }}</p>
        </div>
        <StatusBadge :label="reservationStatus[reservation.status].label" :tone="reservationStatus[reservation.status].tone" />
      </li>
    </ul>
    <p v-if="total > items.length" class="border-t border-vz-border px-4 py-2 text-xs text-vz-muted">Y {{ total - items.length }} más.</p>
  </section>
</template>
