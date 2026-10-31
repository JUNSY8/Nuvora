import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from '@/app/App.vue'
import { router } from '@/router'

import 'remixicon/fonts/remixicon.css'
import '@/app/styles.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.mount('#app')
