<script setup lang="ts">
import { lotStatus } from '@/modules/parking/labels'
import type { ParkingLotInput, ParkingLotStatus } from '@/modules/parking/types'

const props = defineProps<{
  defaultTimezone: string
  errors: Record<string, string>
  idPrefix: string
}>()

const local = defineModel<ParkingLotInput>({ required: true })

const timezones = ['America/Bogota', 'America/Lima', 'America/Mexico_City', 'America/Panama', 'America/Guayaquil', 'America/Santiago', 'America/Argentina/Buenos_Aires', 'UTC']
const statuses = Object.keys(lotStatus) as ParkingLotStatus[]

function id(field: string): string {
  return `${props.idPrefix}-${field}`
}
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2">
    <div>
      <label class="vz-label" :for="id('name')">Nombre</label>
      <input :id="id('name')" v-model="local.name" class="vz-input" type="text" maxlength="120" required autofocus :aria-invalid="!!errors.name" :aria-describedby="errors.name ? id('name-error') : undefined" />
      <p v-if="errors.name" :id="id('name-error')" class="vz-error" role="alert">{{ errors.name }}</p>
    </div>
    <div>
      <label class="vz-label" :for="id('code')">Código</label>
      <input :id="id('code')" v-model="local.code" class="vz-input uppercase" type="text" maxlength="40" required placeholder="CENTRO" :aria-invalid="!!errors.code" :aria-describedby="errors.code ? id('code-error') : id('code-help')" />
      <p v-if="errors.code" :id="id('code-error')" class="vz-error" role="alert">{{ errors.code }}</p>
      <p v-else :id="id('code-help')" class="vz-help">Identificador corto para reportes y señalización.</p>
    </div>
    <div class="sm:col-span-2">
      <label class="vz-label" :for="id('description')">Descripción <span class="text-vz-muted">(opcional)</span></label>
      <textarea :id="id('description')" v-model="local.description" class="vz-input" rows="2" maxlength="500" />
    </div>
    <div>
      <label class="vz-label" :for="id('address')">Dirección</label>
      <input :id="id('address')" v-model="local.address" class="vz-input" type="text" maxlength="200" placeholder="Calle 10 # 5-20" />
    </div>
    <div>
      <label class="vz-label" :for="id('city')">Ciudad</label>
      <input :id="id('city')" v-model="local.city" class="vz-input" type="text" maxlength="80" placeholder="Bogotá" />
    </div>
    <div>
      <label class="vz-label" :for="id('latitude')">Latitud <span class="text-vz-muted">(opcional)</span></label>
      <input :id="id('latitude')" v-model="local.latitude" class="vz-input" type="text" inputmode="decimal" placeholder="4.6097" :aria-invalid="!!errors.latitude" />
      <p v-if="errors.latitude" class="vz-error" role="alert">{{ errors.latitude }}</p>
    </div>
    <div>
      <label class="vz-label" :for="id('longitude')">Longitud <span class="text-vz-muted">(opcional)</span></label>
      <input :id="id('longitude')" v-model="local.longitude" class="vz-input" type="text" inputmode="decimal" placeholder="-74.0817" :aria-invalid="!!errors.longitude" />
      <p v-if="errors.longitude" class="vz-error" role="alert">{{ errors.longitude }}</p>
    </div>
    <div>
      <label class="vz-label" :for="id('timezone')">Zona horaria</label>
      <select :id="id('timezone')" v-model="local.timezone" class="vz-input" :aria-invalid="!!errors.timezone">
        <option v-for="zone in Array.from(new Set([defaultTimezone, local.timezone, ...timezones]))" :key="zone" :value="zone">{{ zone }}</option>
      </select>
      <p v-if="errors.timezone" class="vz-error" role="alert">{{ errors.timezone }}</p>
    </div>
    <div>
      <label class="vz-label" :for="id('currency')">Moneda</label>
      <input :id="id('currency')" v-model="local.currency" class="vz-input uppercase" type="text" maxlength="3" required :aria-invalid="!!errors.currency" />
      <p v-if="errors.currency" class="vz-error" role="alert">{{ errors.currency }}</p>
    </div>
    <div>
      <label class="vz-label" :for="id('grace')">Tiempo de gracia (minutos)</label>
      <input :id="id('grace')" v-model.number="local.grace_period_minutes" class="vz-input" type="number" min="0" max="240" required :aria-invalid="!!errors.grace_period_minutes" :aria-describedby="id('grace-help')" />
      <p v-if="errors.grace_period_minutes" class="vz-error" role="alert">{{ errors.grace_period_minutes }}</p>
      <p v-else :id="id('grace-help')" class="vz-help">Tiempo para salir después de pagar.</p>
    </div>
    <div>
      <label class="vz-label" :for="id('status')">Estado</label>
      <select :id="id('status')" v-model="local.status" class="vz-input">
        <option v-for="status in statuses" :key="status" :value="status">{{ lotStatus[status].label }}</option>
      </select>
    </div>
  </div>
</template>
